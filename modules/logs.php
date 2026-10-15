<?php
// Modul Logs: jejak aktivitas panel.
declare(strict_types=1);

function logs_render(): void {
    $rows = db()->query("SELECT * FROM activity ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>200 aktivitas terakhir</h2></div>
      <div class="tscroll"><table class="twrap">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Belum ada aktivitas.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr><td class="mono"><?= e($r['created_at']) ?></td><td class="mono"><?= e($r['username']) ?></td>
          <td class="mono"><?= e($r['action']) ?></td><td class="mono"><?= e($r['detail']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php
    logs_server();
}

/** Whitelist file log server (read-only). */
function server_logs(): array {
    $out = [];
    foreach (glob('/www/wwwlogs/*.log') ?: [] as $f) {
        if (is_file($f) && is_readable($f)) $out[] = $f;
    }
    sort($out);
    return $out;
}

function tail_file(string $path, int $lines = 300, int $maxBytes = 524288): array {
    $size = @filesize($path);
    if ($size === false) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    if ($size > $maxBytes) fseek($fh, $size - $maxBytes);
    $data = stream_get_contents($fh);
    fclose($fh);
    if ($data === false) return [];
    $all = explode("\n", $data);
    if ($size > $maxBytes) array_shift($all); // baris pertama mungkin terpotong
    return array_slice($all, -$lines);
}

function logs_server(): void {
    $files = server_logs();
    $sel = $_GET['log'] ?? '';
    $flt = trim($_GET['flt'] ?? '');
    $valid = in_array($sel, $files, true) ? $sel : '';
    ?>
    <div class="panel"><div class="panel-head"><h2>Log server</h2>
      <span class="muted mono" style="font-size:11px">read-only · 300 baris terakhir</span></div>
      <div style="padding:16px">
        <form method="get" class="row">
          <input type="hidden" name="page" value="logs">
          <select class="input mono" name="log">
            <option value="">— pilih file —</option>
            <?php foreach ($files as $f): ?>
              <option value="<?= e($f) ?>" <?= $valid === $f ? 'selected' : '' ?>><?= e(basename($f)) ?> (<?= e(fmt_bytes((int)@filesize($f))) ?>)</option>
            <?php endforeach; ?>
          </select>
          <input class="input mono" name="flt" placeholder="filter teks…" value="<?= e($flt) ?>">
          <button class="btn" type="submit">Tampil</button>
        </form>
      </div>
      <?php if ($valid !== ''):
        $lines = tail_file($valid);
        if ($flt !== '') $lines = array_values(array_filter($lines, fn($l) => stripos($l, $flt) !== false));
        ?>
        <div style="padding:0 16px 16px"><pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto;max-height:60vh;font-size:11.5px;margin:0"><?= e(implode("\n", $lines) ?: '(kosong / tidak cocok filter)') ?></pre></div>
      <?php elseif (!$files): ?>
        <div style="padding:0 16px 16px" class="muted">Tidak ada file log yang bisa dibaca.</div>
      <?php endif; ?>
    </div>
    <?php
}
