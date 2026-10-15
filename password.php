<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/auth.php';
start_session();
$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$st = db()->prepare("SELECT must_change FROM users WHERE username=?");
$st->execute([$user]);
$force = isset($_GET['force']) || ((int)($st->fetchColumn() ?: 0) === 1);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['p1'] ?? '');
    $p2 = (string)($_POST['p2'] ?? '');
    if ($p1 !== $p2) $err = 'Konfirmasi tidak sama.';
    elseif (strlen($p1) < 12) $err = 'Minimal 12 karakter. Pakai kalimat+gambar? contoh bebas.';
    else {
        db()->prepare("UPDATE users SET pass_hash=?,must_change=0 WHERE username=?")
            ->execute([password_hash($p1, PASSWORD_ARGON2ID), $user]);
        log_activity($user, 'password_change', '');
        header('Location: index.php?pw=1');
        exit;
    }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password — dhexx panel</title>
<link rel="stylesheet" href="assets/style.css?v=14">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg"></head><body>
<div class="login-wrap"><div class="login-card">
<h1>Ganti password</h1>
<p class="mono"><?= $force ? 'wajib: password sementara terlalu lemah' : 'settings / password' ?> — <?= e($user) ?></p>
<?php if ($err): ?><div class="alert alert-err"><?= e($err) ?></div><?php endif; ?>
<form method="post">
<div style="margin-bottom:12px"><label class="lbl">Password baru (12+)</label><input class="input" type="password" name="p1" required></div>
<div style="margin-bottom:16px"><label class="lbl">Ulangi</label><input class="input" type="password" name="p2" required></div>
<button class="btn btn-primary" type="submit">Simpan</button>
<?php if (!$force): ?><a class="btn" href="index.php" style="margin-left:8px">Batal</a><?php endif; ?>
</form></div></div></body></html>
