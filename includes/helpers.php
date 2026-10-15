<?php
declare(strict_types=1);

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    start_session();
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        http_response_code(419);
        exit('CSRF gagal. Muat ulang halaman.');
    }
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    $u = ['KB','MB','GB','TB'];
    $i = 0; $v = $b / 1024;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return round($v, 1) . ' ' . $u[$i];
}

/** Kategorikan file by MIME + ekstensi. */
function file_cat(string $tmp, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $img = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','avif'];
    $doc = ['pdf','txt','md','doc','docx','xls','xlsx','ppt','pptx','csv','odt','rtf'];
    $vid = ['mp4','webm','mov','mkv','avi'];
    $arc = ['zip','tar','gz','rar','7z','bz2','xz'];
    $aud = ['mp3','wav','ogg','flac','m4a'];
    if (in_array($ext, $img, true)) return 'images';
    if (in_array($ext, $doc, true)) return 'documents';
    if (in_array($ext, $vid, true)) return 'videos';
    if (in_array($ext, $arc, true)) return 'archives';
    if (in_array($ext, $aud, true)) return 'audio';
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $m = $f ? finfo_file($f, $tmp) : '';
        if ($f) finfo_close($f);
        if (str_starts_with((string)$m, 'image/')) return 'images';
        if (str_starts_with((string)$m, 'video/')) return 'videos';
        if (str_starts_with((string)$m, 'audio/')) return 'audio';
        if (in_array($m, ['application/pdf','text/plain','text/markdown','text/csv'], true)) return 'documents';
    }
    return 'others';
}

/** Amankan nama file; tolak ekstensi berbahaya. */
function safe_name(string $name): ?string {
    $base = basename($name);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    $base = trim($base, '._');
    if ($base === '' || strlen($base) > 120) return null;
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (in_array($ext, BLOCKED_EXT, true)) return null;
    return $base;
}

/** Resolve path file di dalam STORAGE_ROOT, cegah traversal. */
function storage_path(string $cat, string $name): ?string {
    if (!in_array($cat, FILE_CATS, true)) return null;
    $safe = safe_name($name);
    if (!$safe) return null;
    $dir = realpath(STORAGE_ROOT . '/' . $cat);
    if ($dir === false) return null;
    return $dir . '/' . $safe;
}
