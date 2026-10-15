<?php
// Antrean kerja istimewa: PHP web (tanpa shell) titip job, worker root mengeksekusi.
declare(strict_types=1);

function queue_dir(): string {
    $d = DATA_DIR . '/queue';
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

/** Titip job ke worker. Kembalikan ID atau null bila gagal. */
function queue_job(string $op, array $args): ?string {
    $allowed = ['git.status', 'git.log', 'git.pull', 'git.clone', 'svc.nginx-reload', 'svc.php-reload'];
    if (!in_array($op, $allowed, true)) return null;
    $id = bin2hex(random_bytes(8));
    $job = ['op' => $op, 'args' => $args, 'user' => current_user() ?? 'webhook', 'ts' => time()];
    if (file_put_contents(queue_dir() . '/' . $id . '.job', json_encode($job)) === false) return null;
    return $id;
}

/** Tunggu hasil worker; null bila timeout (cek lagi nanti). */
function job_result(string $id, int $wait = 20): ?array {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $rf = queue_dir() . '/' . $id . '.result.json';
    $t = 0;
    while ($t < $wait) {
        clearstatcache(true, $rf);
        if (is_file($rf)) {
            $r = json_decode((string)file_get_contents($rf), true);
            @unlink($rf);
            return is_array($r) ? $r : null;
        }
        sleep(1);
        $t++;
    }
    return null;
}

/** Resolve direktori project: relatif -> dalam dashboard; absolut -> wajib di /www/wwwroot. */
function project_dir(string $path): ?string {
    $path = trim($path);
    if ($path === '') return null;
    if (str_starts_with($path, '/')) {
        $rp = realpath($path);
        if ($rp === false || !is_dir($rp)) return null;
        if ($rp !== '/www/wwwroot' && !str_starts_with($rp, '/www/wwwroot/')) return null;
        return $rp;
    }
    if (str_contains($path, '..')) return null;
    $full = APP_ROOT . '/' . trim($path, '/');
    $rp = realpath($full);
    if ($rp === false || !is_dir($rp)) return null;
    $base = realpath(APP_ROOT);
    if ($rp !== $base && !str_starts_with($rp, $base . '/')) return null;
    return $rp;
}

/** Validasi URL repo git (https publik saja). */
function valid_repo(string $url): bool {
    return (bool)preg_match('#^https://[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)+(\.git)?$#', $url);
}

function valid_branch(string $b): bool {
    return (bool)preg_match('#^[A-Za-z0-9/_.\-]{1,64}$#', $b);
}
