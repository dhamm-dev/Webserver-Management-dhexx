<?php
// Session + guard login + rate limit.
declare(strict_types=1);

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true, // aktifkan setelah HTTPS
        ]);
        session_start();
    }
}

function current_user(): ?string {
    start_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): string {
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function login_blocked(string $ip): bool {
    $st = db()->prepare("SELECT fails,last_at FROM login_attempts WHERE ip=?");
    $st->execute([$ip]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if ((int)$r['fails'] >= LOGIN_MAX_FAIL && (time() - (int)$r['last_at']) < LOGIN_BLOCK_SECONDS) {
        return true;
    }
    return false;
}

function login_fail(string $ip): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT fails FROM login_attempts WHERE ip=?");
    $st->execute([$ip]);
    if ($st->fetch()) {
        $pdo->prepare("UPDATE login_attempts SET fails=fails+1,last_at=? WHERE ip=?")->execute([time(), $ip]);
    } else {
        $pdo->prepare("INSERT INTO login_attempts(ip,fails,last_at) VALUES(?,1,?)")->execute([$ip, time()]);
    }
}

function login_reset(string $ip): void {
    db()->prepare("DELETE FROM login_attempts WHERE ip=?")->execute([$ip]);
}
