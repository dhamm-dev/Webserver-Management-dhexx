<?php
// Layout Bare Metal: sidebar + topbar. Dipakai semua halaman.
declare(strict_types=1);

function icon(string $n): string {
    $p = [
        'files' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'projects' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'db' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'sys' => '<rect x="3" y="4" width="18" height="13" rx="1"/><path d="M8 21h8M12 17v4"/>',
        'logs' => '<path d="M5 3h11l3 3v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M9 12h6M9 16h6"/>',
        'images' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'documents' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'videos' => '<rect x="2" y="6" width="13" height="12" rx="2"/><path d="m22 8-6 4 6 4V8Z"/>',
        'archives' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'audio' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'others' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'play' => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'move' => '<path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><path d="M2 12h20"/><path d="M12 2v20"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? $p['others']) . '</svg>';
}

function cat_counts(): array {
    $out = [];
    foreach (FILE_CATS as $c) {
        $d = STORAGE_ROOT . '/' . $c;
        $out[$c] = (is_dir($d)) ? count(array_diff(scandir($d) ?: [], ['.', '..'])) : 0;
    }
    return $out;
}

function layout_open(string $user, string $active, string $crumb, string $title, string $sub = ''): void {
    $counts = cat_counts();
    // Overlay transisi habis login: tampil sekali, geser pergi ke atas.
    $just = false;
    if (!empty($_SESSION['just_logged_in'])) { $just = true; unset($_SESSION['just_logged_in']); }
    $nav = [
        'files' => ['Files', 'files'],
        'projects' => ['Projects', 'projects'],
        'databases' => ['Databases', 'db'],
        'system' => ['System', 'sys'],
        'logs' => ['Logs', 'logs'],
    ];
    ?>
<!doctype html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — dhexx panel</title>
<link rel="stylesheet" href="assets/style.css?v=14">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg"></head><body>
<?php if ($just): ?><div class="wipe cover" id="wipeIn"><div class="mono">dhexx / panel</div><div class="barline"><i></i></div></div><?php endif; ?>
<div class="layout">
<aside class="sidebar">
  <div class="brand">dhexx / panel<small>server control</small></div>
  <nav class="nav">
    <div class="nav-label">Manage</div>
    <?php foreach ($nav as $k => [$label, $ic]): ?>
      <a href="?page=<?= $k ?>" class="<?= $active === $k ? 'active' : '' ?>"><?= icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
    <div class="nav-label">Storage</div>
    <?php foreach (['images','documents','videos','archives','audio','others'] as $c): ?>
      <a href="?page=files&cat=<?= $c ?>" class="<?= ($active === 'files' && ($_GET['cat'] ?? '') === $c) ? 'active' : '' ?>">
        <?= icon($c) ?><?= ucfirst($c) ?>
        <span class="count"><?= (int)($counts[$c] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot"><?= e($user) ?> · <a href="password.php">password</a> · <a href="logout.php">logout</a></div>
</aside>
<div class="main">
  <div class="topbar">
    <span class="crumb">dashboard / <b><?= e($crumb) ?></b></span>
    <span class="spacer"></span>
    <span class="user"><?= e($user) ?></span>
    <a class="btn btn-sm" href="<?= e(PHPMYADMIN_URL) ?>" target="_blank" rel="noopener">phpMyAdmin</a>
  </div>
  <div class="content">
    <h1 class="page-title"><?= e($title) ?></h1>
    <?php if ($sub): ?><p class="page-sub"><?= e($sub) ?></p><?php endif; ?>
    <?php
}

function layout_close(): void {
    ?>
  </div>
  <div class="footer">webserver management powered by <a href="https://github.com/dhamm-dev" target="_blank" rel="noopener">dhamm-dev</a> · <?= date('Y') ?></div>
</div>
</div>
<script src="assets/app.js?v=5"></script>
</body></html>
    <?php
}
