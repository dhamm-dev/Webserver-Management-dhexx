<?php
// Webhook deploy GitHub: POST https://HOST/dashboard/webhook.php?token=RAHASIA&project=ID
// Tanpa login; token adalah autentikasinya. Hanya antrekan pull, eksekusi oleh worker.
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/jobs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
$secret = setting_get('webhook_secret');
$token = (string)($_GET['token'] ?? '');
$pid = (int)($_GET['project'] ?? 0);
if ($secret === '' || $token === '' || !hash_equals($secret, $token) || $pid <= 0) {
    http_response_code(403);
    exit('forbidden');
}
$st = db()->prepare("SELECT * FROM projects WHERE id=?");
$st->execute([$pid]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r || trim($r['repo'] ?? '') === '') {
    http_response_code(404);
    exit('no repo');
}
$dir = project_dir($r['path']);
$branch = trim($r['branch'] ?? 'main') ?: 'main';
if ($dir === null || !valid_branch($branch)) {
    http_response_code(422);
    exit('bad path/branch');
}
$id = queue_job('git.pull', ['dir' => $dir, 'branch' => $branch]);
if (!$id) {
    http_response_code(500);
    exit('queue fail');
}
log_activity('webhook', 'deploy_pull', $r['name'] . " (antrean $id)");
http_response_code(200);
echo 'queued';
