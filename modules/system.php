<?php
// Modul System: info read-only + suhu server sekilas.
declare(strict_types=1);

/** shell_exec aman: kembalikan '' bila fungsi dimatikan host (aaPanel disable_functions). */
function run_cmd(string $cmd): string {
    if (!function_exists('shell_exec')) return '';
    try {
        $o = shell_exec($cmd);
    } catch (Throwable $e) {
        return '';
    }
    return is_string($o) ? $o : '';
}

/** Cek PID hidup + cocok nama proses (tanpa shell, lewat /proc). */
function proc_alive(int $pid, string $match): bool {
    if ($pid <= 0) return false;
    $cmd = @file_get_contents("/proc/$pid/cmdline");
    if ($cmd === false) return false;
    return stripos(str_replace("\0", ' ', $cmd), $match) !== false;
}

function pid_from_file(string $f): int {
    $c = @file_get_contents($f);
    return $c === false ? 0 : (int)trim($c);
}

/** Scan /proc untuk proses bernama $match (tanpa shell). */
function proc_find(string $match): bool {
    $d = @opendir('/proc');
    if (!$d) return false;
    try {
        while (($e = readdir($d)) !== false) {
            if (!ctype_digit($e)) continue;
            $cmd = @file_get_contents("/proc/$e/cmdline");
            if ($cmd === false) continue;
            if (stripos(str_replace("\0", ' ', $cmd), $match) !== false) {
                closedir($d);
                return true;
            }
        }
    } catch (Throwable $e) {
        // abaikan
    }
    @closedir($d);
    return false;
}

/** Tes port TCP lokal terbuka (tanpa shell). */
function port_open(string $host, int $port): bool {
    if (!function_exists('fsockopen')) return false;
    try {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    } catch (Throwable $e) {
        return false;
    }
    if (!$fp) return false;
    fclose($fp);
    return true;
}

/** Tes socket unix php-fpm (tanpa shell). */
function fpm_socket_ok(): bool {
    $sock = '/tmp/php-cgi-85.sock';
    if (!file_exists($sock)) return false;
    if (!function_exists('stream_socket_client')) return true; // file ada, tak bisa tes lebih jauh
    try {
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2);
    } catch (Throwable $e) {
        return false;
    }
    if (!$fp) return false;
    fclose($fp);
    return true;
}

/** Status service 3 lapis: pidfile+/proc -> scan /proc -> shell pidof -> tes port/socket. */
function svc_status(array $svc): bool {
    if (!empty($svc['pidfile']) && !empty($svc['match'])) {
        if (proc_alive(pid_from_file($svc['pidfile']), $svc['match'])) return true;
    }
    if (!empty($svc['match']) && proc_find($svc['match'])) return true;
    if (!empty($svc['pidof'])) {
        if (trim(run_cmd($svc['pidof'] . ' 2>/dev/null')) !== '') return true;
    }
    if (!empty($svc['port']) && port_open('127.0.0.1', (int)$svc['port'])) return true;
    if (!empty($svc['fpm_socket']) && fpm_socket_ok()) return true;
    return false;
}

function sys_handle(string $user): array {
    $flash = ['ok' => '', 'err' => ''];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $flash;
    csrf_check();
    $act = $_POST['act'] ?? '';
    $map = [
        'svc_nginx' => ['svc.nginx-reload', 'Reload Nginx'],
        'svc_php' => ['svc.php-reload', 'Reload PHP-FPM'],
    ];
    if (!isset($map[$act])) return $flash;
    [$op, $label] = $map[$act];
    $id = queue_job($op, []);
    if (!$id) { $flash['err'] = 'Gagal membuat antrean.'; return $flash; }
    start_session();
    $res = job_result($id, 25);
    if ($res === null) { $flash['err'] = 'Worker belum selesai (>25 dtk). Cek Logs.'; return $flash; }
    $_SESSION['svc_out'] = $res['out'] ?? '';
    if (!empty($res['ok'])) {
        log_activity($user, 'svc_reload', $label);
        $flash['ok'] = $label . ': OK.';
    } else {
        $flash['err'] = $label . ' gagal.';
    }
    return $flash;
}

/** Uptime dari /proc/uptime (tanpa shell). */
function sys_uptime(): string {
    $c = @file_get_contents('/proc/uptime');
    if ($c === false) return run_cmd('uptime -p 2>/dev/null');
    $s = (int)explode(' ', trim($c))[0];
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    if ($d > 0) return "up $d hari $h jam";
    if ($h > 0) return "up $h jam $m mnt";
    return "up $m mnt";
}

function sys_render(): void {
    $diskFree = disk_free_space(APP_ROOT) ?: 0;
    $diskTotal = disk_total_space(APP_ROOT) ?: 1;
    $diskUsed = $diskTotal - $diskFree;
    $pct = round($diskUsed / $diskTotal * 100, 1);
    $mem = @file_get_contents('/proc/meminfo') ?: '';
    preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
    $memTotal = isset($mt[1]) ? (int)$mt[1] * 1024 : 0;
    $memAvail = isset($ma[1]) ? (int)$ma[1] * 1024 : 0;
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
    $uptime = sys_uptime();
    $defs = [
        'nginx' => ['pidfile' => '/www/server/nginx/logs/nginx.pid', 'match' => 'nginx', 'pidof' => 'pidof nginx', 'port' => 80],
        'php-fpm' => ['pidfile' => '/www/server/php/85/var/run/php-fpm.pid', 'match' => 'php-fpm', 'pidof' => 'pidof php-fpm', 'fpm_socket' => true],
        'mysql' => ['pidfile' => '/www/server/data/dhexweb.pid', 'match' => 'mysqld', 'pidof' => 'pidof mysqld', 'port' => 3306],
    ];
    $services = [];
    foreach ($defs as $k => $def) {
        $services[$k] = svc_status($def);
    }
    ?>
    <div class="stats">
      <div class="stat"><div class="k">Disk used</div><div class="v"><?= e(fmt_bytes((int)$diskUsed)) ?> <span class="muted" style="font-size:12px">/ <?= e(fmt_bytes((int)$diskTotal)) ?></span></div><div class="bar"><i style="width:<?= e((string)$pct) ?>%"></i></div></div>
      <div class="stat"><div class="k">Memory</div><div class="v"><?= $memTotal ? e(fmt_bytes($memTotal - $memAvail)) : '—' ?> <span class="muted" style="font-size:12px"><?= $memTotal ? '/ ' . e(fmt_bytes($memTotal)) : '' ?></span></div>
        <?php if ($memTotal): ?><div class="bar"><i style="width:<?= round(($memTotal - $memAvail) / $memTotal * 100, 1) ?>%"></i></div><?php endif; ?></div>
      <div class="stat"><div class="k">Load (1/5/15)</div><div class="v mono" style="font-size:16px"><?= e(implode(' / ', array_map(fn($v) => number_format((float)$v, 2), $load))) ?></div><div class="muted mono" style="font-size:11px"><?= e(trim($uptime)) ?></div></div>
      <div class="stat"><div class="k">PHP</div><div class="v mono" style="font-size:16px"><?= e(PHP_VERSION) ?></div><div class="muted mono" style="font-size:11px"><?= e(php_sapi_name()) ?></div></div>
    </div>
    <div class="panel"><div class="panel-head"><h2>Services</h2></div>
      <div class="tscroll"><table><thead><tr><th>Service</th><th>Status</th><th>Note</th></tr></thead><tbody>
      <?php foreach ($services as $k => $on): ?>
        <tr><td class="mono"><?= e($k) ?></td>
        <td><span class="badge"><span class="dot <?= $on ? 'dot-ok' : 'dot-bad' ?>"></span><?= $on ? 'up' : 'down' ?></span></td>
        <td class="muted mono" style="font-size:12px"><?= $on ? 'terdeteksi hidup' : 'tidak terdeteksi (cek systemctl)' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Restart service</h2>
      <span class="muted mono" style="font-size:11px">via worker · tercatat di Logs</span></div>
      <div style="padding:16px" class="row">
        <?php $t = csrf_token(); ?>
        <form method="post" onsubmit="return confirm('Reload Nginx? (putus sesaat, tanpa downtime)')">
          <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="svc_nginx">
          <button class="btn" type="submit">Reload Nginx</button>
        </form>
        <form method="post" onsubmit="return confirm('Reload PHP-FPM? (request jalan mungkin terganggu sesaat)')">
          <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="svc_php">
          <button class="btn" type="submit">Reload PHP-FPM</button>
        </form>
      </div>
      <?php start_session(); $so = $_SESSION['svc_out'] ?? ''; unset($_SESSION['svc_out']);
      if ($so !== '') echo '<div style="padding:0 16px 16px"><pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:8px;padding:12px;overflow-x:auto;font-size:12px;margin:0">' . e($so) . '</pre></div>'; ?>
    </div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Grafik 24 jam</h2>
      <span class="muted mono" style="font-size:11px">kolektor tiap 5 menit</span></div>
      <div style="padding:16px"><?= metrics_graph(metrics_last24()) ?></div>
    </div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Kolektor metrik</h2></div>
      <div style="padding:16px">
        <?php $cs = setting_get('collect_secret');
        if ($cs === '') { $cs = bin2hex(random_bytes(16)); setting_set('collect_secret', $cs); } ?>
        <p class="muted mono" style="font-size:12px">Pasang di crontab agar grafik terisi (jalan sebagai user biasa, tanpa sudo):</p>
        <pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:8px;padding:12px;overflow-x:auto;font-size:12px">*/5 * * * * curl -sf -H 'Host: YOUR_DOMAIN' 'http://127.0.0.1/index.php?collect=<?= e($cs) ?>' >/dev/null 2>&1</pre>
      </div>
    </div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Storage usage</h2></div>
      <div class="tscroll"><table><thead><tr><th>Folder</th><th class="num">Files</th><th class="num">Size</th></tr></thead><tbody>
      <?php foreach (array_merge(FILE_CATS, ['projects']) as $c):
        $d = STORAGE_ROOT . '/' . $c;
        $n = 0; $s = 0;
        if (is_dir($d)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile()) { $n++; $s += $f->getSize(); } }
        }
      ?>
        <tr><td class="mono">~/storage/<?= e($c) ?>/</td><td class="num"><?= $n ?></td><td class="num"><?= e(fmt_bytes($s)) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php
}
