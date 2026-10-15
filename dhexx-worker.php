#!/usr/bin/php
<?php
// dhexx worker: dieksekusi root via cron tiap menit. Proses antrean dari dashboard web.
// Testing lokal: php dhexx-worker.php /path/dashboard
declare(strict_types=1);

$ROOT = $argv[1] ?? '/www/wwwroot/YOUR_DOMAIN/dashboard';
define('W_ROOT', $ROOT);
define('W_DATA', $ROOT . '/data');
define('W_QUEUE', $ROOT . '/data/queue');
define('W_DB', $ROOT . '/data/panel.sqlite');

$NGINX_BIN = '/www/server/nginx/sbin/nginx';
$NGINX_CONF = '/www/server/nginx/conf/nginx.conf';
$PHP_PID = '/www/server/php/85/var/run/php-fpm.pid';

function wlog(string $m): void {
    fwrite(STDERR, date('Y-m-d H:i:s') . " [worker] $m\n");
}

function ok_dir(string $dir): ?string {
    $rp = realpath($dir);
    if ($rp === false || !is_dir($rp)) return null;
    if ($rp === '/www/wwwroot' || str_starts_with($rp, '/www/wwwroot/')) return $rp;
    $dash = realpath(W_ROOT . '/storage/projects');
    if ($dash && ($rp === $dash || str_starts_with($rp, $dash . '/'))) return $rp;
    return null;
}

function ok_branch($b): bool {
    return is_string($b) && (bool)preg_match('#^[A-Za-z0-9/_.\-]{1,64}$#', $b);
}

function ok_repo($u): bool {
    return is_string($u) && (bool)preg_match('#^https://[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)+(\.git)?$#', $u);
}

/** Jalankan perintah sebagai user www. */
function as_www(string $cmd): array {
    $full = 'su -s /bin/sh www -c ' . escapeshellarg($cmd) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($full, $out, $code);
    $txt = implode("\n", $out);
    if (strlen($txt) > 4000) $txt = substr($txt, 0, 4000) . "\n…(dipotong)";
    return [$code, $txt];
}

function fix_own(): void {
    foreach (['panel.sqlite', 'panel.sqlite-wal', 'panel.sqlite-shm'] as $f) {
        $p = W_DATA . '/' . $f;
        if (file_exists($p)) {
            @chown($p, 'www');
            @chgrp($p, 'www');
            @chmod($p, 0644);
        }
    }
}

function db_log(string $user, string $action, string $detail): void {
    try {
        $pdo = new PDO('sqlite:' . W_DB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare("INSERT INTO activity(username,action,detail,created_at) VALUES(?,?,?,datetime('now','localtime'))")
            ->execute([$user, $action, $detail]);
        fix_own();
    } catch (Throwable $e) {
        wlog('db_log gagal: ' . $e->getMessage());
    }
}

/** Export env agar git percaya repo walau owner beda (tanpa ubah config permanen). */
function git_env(string $dir): string {
    return 'export GIT_TERMINAL_PROMPT=0 GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=' . escapeshellarg($dir) . '; ';
}

function do_job(array $job): array {
    global $NGINX_BIN, $NGINX_CONF, $PHP_PID;
    $op = $job['op'] ?? '';
    $a = $job['args'] ?? [];
    $who = $job['user'] ?? '?';

    if (in_array($op, ['git.status', 'git.log', 'git.pull', 'git.clone'], true)) {
        $dir = ok_dir((string)($a['dir'] ?? ''));
        if ($dir === null) return ['ok' => false, 'out' => 'Direktori ditolak/bukan repo area.'];
        $q = fn($s) => escapeshellarg($s);
        if ($op === 'git.clone') {
            $repo = (string)($a['repo'] ?? '');
            $branch = (string)($a['branch'] ?? 'main');
            if (!ok_repo($repo) || !ok_branch($branch)) return ['ok' => false, 'out' => 'Repo/branch tidak valid.'];
            if (is_dir($dir . '/.git')) return ['ok' => false, 'out' => 'Sudah repo, pakai Pull.'];
            $left = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
            if ($left === ['README.txt']) {
                @unlink($dir . '/README.txt'); // file sambutan auto-dashboard, boleh diganti isi repo
                $left = [];
            }
            if (count($left) > 0) return ['ok' => false, 'out' => 'Folder tidak kosong.'];
            // clone ke tmp lalu pindah semua (termasuk dotfiles + .git) via tar
            [$code, $out] = as_www(git_env($dir) . 'tmp=$(mktemp -d) && timeout 180 git clone --branch ' . $q($branch) . ' ' . $q($repo) . ' "$tmp" 2>&1 && (cd "$tmp" && tar cf - .) | (cd ' . $q($dir) . ' && tar xf -) 2>&1; rc=$?; rm -rf "$tmp"; exit $rc');
            $ok = ($code === 0);
            db_log($who, 'deploy_clone', "$dir <- $repo ($branch): " . ($ok ? 'OK' : 'GAGAL'));
            return ['ok' => $ok, 'out' => $out];
        }
        if (!is_dir($dir . '/.git')) return ['ok' => false, 'out' => 'Bukan repo git.'];
        if ($op === 'git.status') {
            [$code, $out] = as_www(git_env($dir) . 'cd ' . $q($dir) . ' && git status -sb 2>&1 | head -5 && git log -1 --format=%h\\ %s 2>&1');
            return ['ok' => $code === 0, 'out' => $out];
        }
        if ($op === 'git.log') {
            [$code, $out] = as_www(git_env($dir) . 'cd ' . $q($dir) . " && git log -5 --format='%h %ad %s' --date=short 2>&1");
            return ['ok' => $code === 0, 'out' => $out];
        }
        // git.pull
        $branch = (string)($a['branch'] ?? 'main');
        if (!ok_branch($branch)) return ['ok' => false, 'out' => 'Branch tidak valid.'];
        [$code, $out] = as_www(git_env($dir) . 'cd ' . $q($dir) . ' && timeout 180 git fetch origin ' . $q($branch) . ' 2>&1 && git checkout ' . $q($branch) . ' 2>&1 && git pull --ff-only origin ' . $q($branch) . ' 2>&1');
        $ok = ($code === 0);
        db_log($who, 'deploy_pull', "$dir ($branch): " . ($ok ? 'OK' : 'GAGAL'));
        return ['ok' => $ok, 'out' => $out];
    }

    if ($op === 'svc.nginx-reload') {
        $out = [];
        $code = 0;
        exec($NGINX_BIN . ' -t -c ' . escapeshellarg($NGINX_CONF) . ' 2>&1', $out, $code);
        if ($code !== 0) return ['ok' => false, 'out' => "nginx -t GAGAL:\n" . implode("\n", $out)];
        exec($NGINX_BIN . ' -s reload -c ' . escapeshellarg($NGINX_CONF) . ' 2>&1', $out2, $code2);
        $txt = implode("\n", array_merge($out, $out2 ?? []));
        db_log($who, 'svc_reload', 'nginx: ' . ($code2 === 0 ? 'OK' : 'GAGAL'));
        return ['ok' => $code2 === 0, 'out' => $txt === '' ? 'nginx reloaded.' : $txt];
    }

    if ($op === 'svc.php-reload') {
        $pid = trim((string)@file_get_contents($PHP_PID));
        if (!ctype_digit($pid)) return ['ok' => false, 'out' => 'PID php-fpm tidak valid.'];
        $cmd = @file_get_contents("/proc/$pid/cmdline");
        if ($cmd === false || stripos(str_replace("\0", ' ', $cmd), 'php-fpm') === false) {
            return ['ok' => false, 'out' => 'Proses php-fpm tidak ditemukan.'];
        }
        exec('kill -USR2 ' . escapeshellarg($pid) . ' 2>&1', $out, $code);
        db_log($who, 'svc_reload', 'php-fpm: ' . ($code === 0 ? 'OK' : 'GAGAL'));
        return ['ok' => $code === 0, 'out' => $code === 0 ? "php-fpm reloaded (pid $pid)." : implode("\n", $out)];
    }

    return ['ok' => false, 'out' => 'Operasi tidak dikenal.'];
}

// ---- main ----
$lock = @fopen(W_DATA . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    wlog('worker lain jalan, keluar.');
    exit(0);
}
if (!is_dir(W_QUEUE)) {
    flock($lock, LOCK_UN);
    exit(0);
}
foreach (glob(W_QUEUE . '/*.job') ?: [] as $jf) {
    $id = basename($jf, '.job');
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { @unlink($jf); continue; }
    if (time() - (int)@filemtime($jf) > 600) {
        @unlink($jf);
        file_put_contents(W_QUEUE . '/' . $id . '.result.json', json_encode(['ok' => false, 'out' => 'Timeout: worker tidak sempat proses.']));
        @chmod(W_QUEUE . '/' . $id . '.result.json', 0644);
        continue;
    }
    $job = json_decode((string)@file_get_contents($jf), true);
    if (!is_array($job)) { @unlink($jf); continue; }
    try {
        $res = do_job($job);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'out' => 'Worker error: ' . $e->getMessage()];
    }
    file_put_contents(W_QUEUE . '/' . $id . '.result.json', json_encode($res));
    @chmod(W_QUEUE . '/' . $id . '.result.json', 0644);
    @unlink($jf);
}
// bersih-bersih hasil lama
foreach (glob(W_QUEUE . '/*.result.json') ?: [] as $rf) {
    if (time() - (int)@filemtime($rf) > 3600) @unlink($rf);
}
flock($lock, LOCK_UN);
