<?php
// Setup sekali: buat user admin awal bila tabel users masih kosong.
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $u)) {
        $msg = 'Username 3-32 karakter (huruf/angka/_.-).';
    } elseif (strlen($p) < 4) {
        $msg = 'Password minimal 4 karakter (nanti wajib diganti ke 12+).';
    } else {
        $n = (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($n > 0) { http_response_code(403); exit('Setup sudah pernah dijalankan. Hapus file setup.php sekarang.'); }
        db()->prepare("INSERT INTO users(username,pass_hash,must_change,created_at) VALUES(?,?,1,datetime('now','localtime'))")
            ->execute([$u, password_hash($p, PASSWORD_ARGON2ID)]);
        header('Location: login.php?setup=1');
        exit;
    }
}
$count = (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count > 0) { http_response_code(403); exit('Setup sudah selesai. Hapus file setup.php.'); }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — dhexx panel</title>
<link rel="stylesheet" href="assets/style.css?v=12">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg"></head><body>
<div class="login-wrap"><div class="login-card">
<h1>Setup admin</h1><p>Dibuka sekali saja. Setelah ini hapus file setup.php.</p>
<?php if ($msg): ?><div class="alert alert-err"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<form method="post">
<div style="margin-bottom:12px"><label class="lbl">Username</label><input class="input mono" name="username" placeholder="username-admin" required></div>
<div style="margin-bottom:16px"><label class="lbl">Password sementara</label><input class="input" type="password" name="password" required><p class="muted mono" style="font-size:11px">Gunakan password sementara yang kuat — login pertama langsung dipaksa ganti ke 12+ karakter.</p></div>
<button class="btn btn-primary" type="submit">Buat admin</button>
</form></div></div></body></html>
