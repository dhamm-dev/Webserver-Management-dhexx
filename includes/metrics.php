<?php
// Metrik server: koleksi ringan via /proc, grafik SVG tanpa lib.
declare(strict_types=1);

function metrics_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . DATA_DIR . '/metrics.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS metrics(
        ts INTEGER PRIMARY KEY,
        cpu REAL NOT NULL DEFAULT 0,
        mem_used INTEGER NOT NULL DEFAULT 0,
        mem_total INTEGER NOT NULL DEFAULT 1,
        disk_used INTEGER NOT NULL DEFAULT 0,
        disk_total INTEGER NOT NULL DEFAULT 1,
        load1 REAL NOT NULL DEFAULT 0,
        cpu_total INTEGER NOT NULL DEFAULT 0,
        cpu_idle INTEGER NOT NULL DEFAULT 0
    )");
    return $pdo;
}

function cpu_counters(): array {
    $l = @file_get_contents('/proc/stat');
    if ($l === false) return [0, 0];
    $p = preg_split('/\s+/', trim(explode("\n", $l)[0]));
    array_shift($p); // buang 'cpu'
    $n = array_map('intval', $p);
    $total = array_sum($n);
    $idle = ($n[3] ?? 0) + ($n[4] ?? 0);
    return [$total, $idle];
}

/** Kumpulkan 1 titik metrik + prune >7 hari. */
function collect_metrics(): void {
    [$ct, $ci] = cpu_counters();
    $mem = @file_get_contents('/proc/meminfo') ?: '';
    preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
    $memTotal = isset($mt[1]) ? (int)$mt[1] * 1024 : 1;
    $memAvail = isset($ma[1]) ? (int)$ma[1] * 1024 : 0;
    $diskTotal = disk_total_space(APP_ROOT) ?: 1;
    $diskFree = disk_free_space(APP_ROOT) ?: 0;
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0];
    $pdo = metrics_db();
    // cpu% vs titik sebelumnya
    $prev = $pdo->query("SELECT cpu_total, cpu_idle FROM metrics ORDER BY ts DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $cpu = 0.0;
    if ($prev && $ct > (int)$prev['cpu_total']) {
        $dt = $ct - (int)$prev['cpu_total'];
        $di = $ci - (int)$prev['cpu_idle'];
        if ($dt > 0) $cpu = round(max(0, min(100, (1 - $di / $dt) * 100)), 1);
    }
    $pdo->prepare("INSERT OR REPLACE INTO metrics(ts,cpu,mem_used,mem_total,disk_used,disk_total,load1,cpu_total,cpu_idle) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([time(), $cpu, $memTotal - $memAvail, $memTotal, $diskTotal - $diskFree, $diskTotal, (float)($load[0] ?? 0), $ct, $ci]);
    $pdo->exec("DELETE FROM metrics WHERE ts < " . (time() - 7 * 86400));
}

/** Ambil titik-titik s/d 24 jam terakhir (downsample ~288). */
function metrics_last24(): array {
    try {
        $rows = metrics_db()->query("SELECT * FROM metrics WHERE ts > " . (time() - 86400) . " ORDER BY ts ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $n = count($rows);
    if ($n > 288) {
        $step = (int)ceil($n / 288);
        $rows = array_values(array_filter($rows, fn($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
    }
    return $rows;
}

/** Render grafik SVG 2 garis (CPU% oranye, MEM% tinta). */
function metrics_graph(array $rows): string {
    if (count($rows) < 2) return '<div class="muted">Belum ada data (kolektor jalan tiap 5 menit).</div>';
    $W = 600;
    $H = 160;
    $P = 8;
    $n = count($rows);
    $pts = function (callable $f) use ($rows, $n, $W, $H, $P): string {
        $s = [];
        foreach ($rows as $i => $r) {
            $x = $P + ($W - 2 * $P) * ($n === 1 ? 0 : $i / ($n - 1));
            $y = $H - $P - ($H - 2 * $P) * max(0, min(100, $f($r))) / 100;
            $s[] = round($x, 1) . ',' . round($y, 1);
        }
        return implode(' ', $s);
    };
    $mem = fn($r) => $r['mem_total'] > 0 ? $r['mem_used'] / $r['mem_total'] * 100 : 0;
    $cpu = fn($r) => (float)$r['cpu'];
    $t0 = date('H:i', (int)$rows[0]['ts']);
    $t1 = date('H:i', (int)$rows[$n - 1]['ts']);
    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;display:block" role="img" aria-label="Grafik 24 jam">'
        . '<line x1="' . $P . '" y1="' . ($H - $P) . '" x2="' . ($W - $P) . '" y2="' . ($H - $P) . '" stroke="#E2E2DE"/>'
        . '<line x1="' . $P . '" y1="' . $P . '" x2="' . ($W - $P) . '" y2="' . $P . '" stroke="#E2E2DE" stroke-dasharray="3 3"/>'
        . '<polyline points="' . $pts($mem) . '" fill="none" stroke="#111310" stroke-width="1.5"/>'
        . '<polyline points="' . $pts($cpu) . '" fill="none" stroke="#FF4D00" stroke-width="1.5"/>'
        . '<text x="' . $P . '" y="' . ($H - 1) . '" font-size="9" fill="#6B6F6A" font-family="monospace">' . $t0 . '</text>'
        . '<text x="' . ($W - $P - 30) . '" y="' . ($H - 1) . '" font-size="9" fill="#6B6F6A" font-family="monospace">' . $t1 . '</text>'
        . '</svg>'
        . '<div class="row" style="margin-top:8px"><span class="badge"><span class="dot" style="background:#FF4D00"></span>CPU ' . end($rows)['cpu'] . '%</span>'
        . '<span class="badge"><span class="dot" style="background:#111310"></span>MEM ' . round($mem(end($rows))) . '%</span>'
        . '<span class="muted mono" style="font-size:11px">' . $n . ' titik · 24 jam</span></div>';
}
