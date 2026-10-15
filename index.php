<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/mysql_admin.php';
require __DIR__ . '/includes/jobs.php';
require __DIR__ . '/includes/metrics.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/modules/files.php';
require __DIR__ . '/modules/projects.php';
require __DIR__ . '/modules/databases.php';
require __DIR__ . '/modules/system.php';
require __DIR__ . '/modules/logs.php';

// Endpoint kolektor metrik (cron, tanpa login; token rahasia)
if (isset($_GET['collect'])) {
    $s = setting_get('collect_secret');
    if ($s !== '' && hash_equals($s, (string)$_GET['collect'])) {
        collect_metrics();
        exit('OK');
    }
    http_response_code(403);
    exit('forbidden');
}

$user = require_login();
$page = $_GET['page'] ?? 'files';
if (!in_array($page, ['files', 'projects', 'databases', 'system', 'logs'], true)) $page = 'files';

// Export DB harus sebelum output HTML
if ($page === 'databases' && isset($_GET['export'])) {
    $pdo = mysql_conn();
    if (!$pdo) { header('Location: ?page=databases'); exit; }
    databases_export($pdo, $_GET['export']);
}

$flash = ['ok' => '', 'err' => ''];
if ($page === 'files') $flash = files_handle($user);
if ($page === 'projects') $flash = projects_handle($user);
if ($page === 'databases') $flash = databases_handle($user);
if ($page === 'system') $flash = sys_handle($user);

if (isset($_GET['pw'])) $flash['ok'] = 'Password diganti. Selamat datang kembali.';

$titles = [
    'files' => ['Files', 'Upload terstruktur — otomatis dipilah per kategori', 'files'],
    'projects' => ['Projects', 'Katalog project + folder fisik di storage/projects/', 'projects'],
    'databases' => ['Databases', 'Kelola MySQL langsung atau via phpMyAdmin', 'databases'],
    'system' => ['System', 'Kesehatan server sekilas (read-only)', 'system'],
    'logs' => ['Logs', 'Jejak aktivitas panel', 'activity'],
];
[$title, $sub, $crumb] = $titles[$page];

layout_open($user, $page, $crumb, $title, $sub);
if ($page === 'files') files_render($user, $flash);
if ($page === 'projects') projects_render($flash);
if ($page === 'databases') databases_render($flash);
if ($page === 'system') {
    if ($flash['ok']) echo '<div class="alert alert-ok">' . htmlspecialchars($flash['ok']) . '</div>';
    if ($flash['err']) echo '<div class="alert alert-err">' . htmlspecialchars($flash['err']) . '</div>';
    sys_render();
}
if ($page === 'logs') logs_render();
layout_close();
