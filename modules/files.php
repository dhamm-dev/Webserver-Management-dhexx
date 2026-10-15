<?php
// Modul Files: list per kategori + subfolder + upload auto-pilah + rename/hapus/download.
declare(strict_types=1);

/** Normalisasi subpath folder; null bila tidak valid. */
function norm_sub(string $sub): ?string {
    $sub = trim($sub, '/');
    if ($sub === '') return '';
    $parts = explode('/', $sub);
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') return null;
        if (strlen($p) > 60) return null;
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $p)) return null;
    }
    return implode('/', $parts);
}

/** Validasi nama folder baru. */
function safe_dirname(string $name): ?string {
    $b = preg_replace('/\s+/', '_', trim($name));
    if ($b === '' || strlen($b) > 60) return null;
    if ($b === '.' || $b === '..') return null;
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $b)) return null;
    $b = trim($b, '._');
    return $b === '' ? null : $b;
}

function cat_root(string $cat): ?string {
    if (!in_array($cat, FILE_CATS, true)) return null;
    $d = realpath(STORAGE_ROOT . '/' . $cat);
    return $d === false ? null : $d;
}

/** Resolve folder aktif (harus ada + di dalam kategorinya). */
function resolve_dir(string $cat, string $sub): ?string {
    $root = cat_root($cat);
    $norm = norm_sub($sub);
    if ($root === null || $norm === null) return null;
    $p = $norm === '' ? $root : $root . '/' . $norm;
    $rp = realpath($p);
    if ($rp === false || !is_dir($rp)) return null;
    if ($rp !== $root && !str_starts_with($rp, $root . '/')) return null;
    return $rp;
}

/** Resolve file di dalam folder aktif. */
function resolve_file(string $cat, string $sub, string $name): ?string {
    $dir = resolve_dir($cat, $sub);
    $safe = safe_name($name);
    if ($dir === null || !$safe) return null;
    return $dir . '/' . $safe;
}

/** Hapus folder rekursif; kembalikan jumlah item terhapus / null bila ditolak. */
function rrmdir(string $dir, string $root): ?int {
    $rp = realpath($dir);
    if ($rp === false || $rp === $root || !str_starts_with($rp, $root . '/')) return null;
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!str_starts_with($p, $rp)) return null;
        if ($f->isDir() && !$f->isLink()) rmdir($p); else unlink($p);
        $n++;
    }
    rmdir($rp);
    return $n;
}

function count_items(string $dir): int {
    $a = @scandir($dir) ?: [];
    return count(array_diff($a, ['.', '..']));
}

/** Daftar semua subfolder dalam kategori (untuk tujuan move). */
function all_dirs(string $cat): array {
    $root = cat_root($cat);
    if ($root === null) return [''];
    $out = [''];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if ($rel !== '' && norm_sub($rel) !== null) $out[] = $rel;
            }
        }
    } catch (Throwable $e) {
        // abaikan
    }
    sort($out);
    return $out;
}

/** Extract .zip aman ke folder; kembalikan ['ok'=>n,'skip'=>m] / null bila gagal. */
function zip_extract(string $tmp, string $dir): ?array {
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) return null;
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) return null;
    $ok = 0;
    $skip = 0;
    $bytes = 0;
    $count = $z->numFiles;
    if ($count > 1000) { $z->close(); return ['ok' => 0, 'skip' => $count]; }
    for ($i = 0; $i < $count; $i++) {
        $name = $z->getNameIndex($i);
        if ($name === false) { $skip++; continue; }
        $name = str_replace('\\', '/', $name);
        $name = trim($name, '/');
        if ($name === '') continue;
        $parts = explode('/', $name);
        $bad = false;
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') { $bad = true; break; }
        }
        if ($bad) { $skip++; continue; }
        $isDir = str_ends_with($name, '/');
        $ext = strtolower(pathinfo(rtrim($name, '/'), PATHINFO_EXTENSION));
        if (!$isDir && (in_array($ext, BLOCKED_EXT, true) || $ext === 'htaccess')) { $skip++; continue; }
        $dest = $root . '/' . $name;
        if ($isDir) {
            if (!is_dir($dest)) @mkdir($dest, 0755, true);
            continue;
        }
        $bytes += $z->statIndex($i)['size'] ?? 0;
        if ($bytes > 200 * 1024 * 1024) { $skip++; continue; }
        $parent = dirname($dest);
        if (!is_dir($parent)) @mkdir($parent, 0755, true);
        // kunci: pastikan tetap di dalam root setelah normalisasi
        $rp = realpath($parent);
        if ($rp === false || ($rp !== $root && !str_starts_with($rp, $root . '/'))) { $skip++; continue; }
        $in = $z->getStream($z->getNameIndex($i));
        if (!$in) { $skip++; continue; }
        $out = @fopen($dest, 'wb');
        if (!$out) { is_resource($in) && fclose($in); $skip++; continue; }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        chmod($dest, 0644);
        $ok++;
    }
    $z->close();
    return ['ok' => $ok, 'skip' => $skip];
}

/** URL publik file storage (relatif, ikut path deploy). */
function storage_url(string $cat, string $sub, string $name): string {
    $u = 'storage/' . $cat;
    if ($sub !== '') $u .= '/' . implode('/', array_map('rawurlencode', explode('/', $sub)));
    return $u . '/' . rawurlencode($name);
}

const TEXT_PREVIEW = ['txt', 'md', 'markdown', 'log', 'json', 'js', 'css', 'html', 'xml', 'yml', 'yaml', 'csv', 'php', 'py', 'sh', 'sql', 'ini', 'conf'];

/** Encode subpath untuk URL (slash dipertahankan). */
function dir_url(string $sub): string {
    if ($sub === '') return '';
    return implode('/', array_map('rawurlencode', explode('/', $sub)));
}

function files_ctx(): array {
    $cat = $_REQUEST['cat'] ?? 'images';
    if (!in_array($cat, FILE_CATS, true)) $cat = 'images';
    $sub = norm_sub($_REQUEST['dir'] ?? '') ?? '';
    return [$cat, $sub];
}

function files_handle(string $user): array {
    $flash = ['ok' => '', 'err' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        [$cat, $sub] = files_ctx();
        $act = $_POST['act'] ?? '';
        $where = $cat . ($sub !== '' ? '/' . $sub : '');

        if ($act === 'upload' && !empty($_FILES['file'])) {
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $flash['err'] = 'Upload gagal (kode ' . (int)$f['error'] . ').';
            } elseif (($f['size'] ?? 0) > MAX_UPLOAD_BYTES) {
                $flash['err'] = 'Terlalu besar. Maks ' . (MAX_UPLOAD_BYTES / 1048576) . 'MB.';
            } else {
                $safe = safe_name($f['name'] ?? '');
                if (!$safe) {
                    $flash['err'] = 'Nama/tipe file ditolak (termasuk .php dkk).';
                } else {
                    if ($sub === '') {
                        // root kategori: pilah otomatis
                        $cat = file_cat($f['tmp_name'], $safe);
                        $dest = storage_path($cat, $safe);
                        $where = $cat;
                    } else {
                        // dalam folder: tetap di folder aktif
                        $dest = resolve_file($cat, $sub, $safe);
                        $where = $cat . '/' . $sub;
                    }
                    if (!$dest) {
                        $flash['err'] = 'Folder tujuan tidak valid.';
                    } else {
                        if (file_exists($dest)) {
                            $pi = pathinfo($safe);
                            $safe = ($pi['filename'] ?? 'file') . '-' . date('His') . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
                            $dest = ($sub === '') ? storage_path($cat, $safe) : resolve_file($cat, $sub, $safe);
                        }
                        if ($dest && move_uploaded_file($f['tmp_name'], $dest)) {
                            chmod($dest, 0644);
                            log_activity($user, 'file_upload', $where . '/' . $safe);
                            $flash['ok'] = 'Tersimpan di ' . $where . '/' . $safe;
                        } else {
                            $flash['err'] = 'Gagal memindahkan file.';
                        }
                    }
                }
            }
        }

        // Mode AJAX (popup upload): balas JSON, bukan render halaman.
        if ($act === 'upload' && (($_SERVER['HTTP_X_UPLOAD_MODE'] ?? '') === 'ajax')) {
            header('Content-Type: application/json');
            if ($flash['err'] !== '' || empty($_FILES['file'])) {
                echo json_encode(['ok' => false, 'error' => $flash['err'] !== '' ? $flash['err'] : 'Tidak ada file.']);
            } else {
                echo json_encode(['ok' => true, 'file' => $where . '/' . ($safe ?? '')]);
            }
            exit;
        }

        if ($act === 'mkdir') {
            $name = safe_dirname($_POST['name'] ?? '');
            $dir = resolve_dir($cat, $sub);
            if (!$name || $dir === null) {
                $flash['err'] = 'Nama folder ditolak (huruf/angka/_.-, maks 60).';
            } elseif (file_exists($dir . '/' . $name)) {
                $flash['err'] = 'Sudah ada file/folder bernama itu.';
            } elseif (mkdir($dir . '/' . $name, 0755)) {
                log_activity($user, 'dir_create', $where . '/' . $name);
                $flash['ok'] = 'Folder dibuat: ' . $name;
            } else {
                $flash['err'] = 'Gagal membuat folder.';
            }
        }

        if ($act === 'rmdir') {
            $root = cat_root($cat);
            $name = safe_dirname($_POST['name'] ?? '');
            $dir = resolve_dir($cat, $sub);
            if (!$name || $dir === null || $root === null) {
                $flash['err'] = 'Folder tidak valid.';
            } else {
                $target = $dir . '/' . $name;
                $n = is_dir($target) ? rrmdir($target, $root) : null;
                if ($n === null) {
                    $flash['err'] = 'Gagal / ditolak menghapus folder.';
                } else {
                    log_activity($user, 'dir_delete', $where . '/' . $name . " ($n item)");
                    $flash['ok'] = "Folder $name dihapus ($n item).";
                }
            }
        }

        if ($act === 'rename_dir') {
            $old = safe_dirname($_POST['old'] ?? '');
            $new = safe_dirname($_POST['new'] ?? '');
            $dir = resolve_dir($cat, $sub);
            if (!$old || !$new || $dir === null) {
                $flash['err'] = 'Nama folder ditolak.';
            } elseif (!is_dir($dir . '/' . $old) || file_exists($dir . '/' . $new)) {
                $flash['err'] = 'Rename folder gagal (tidak ada / nama sudah dipakai).';
            } elseif (rename($dir . '/' . $old, $dir . '/' . $new)) {
                log_activity($user, 'dir_rename', $where . '/' . $old . ' -> ' . $new);
                $flash['ok'] = 'Folder di-rename.';
            } else {
                $flash['err'] = 'Rename folder gagal.';
            }
        }

        if ($act === 'delete') {
            $p = resolve_file($cat, $sub, $_POST['name'] ?? '');
            if ($p && is_file($p)) {
                unlink($p);
                log_activity($user, 'file_delete', $where . '/' . basename($_POST['name']));
                $flash['ok'] = 'Dihapus.';
            } else {
                $flash['err'] = 'File tidak ditemukan.';
            }
        }

        if ($act === 'rename') {
            $new = safe_name($_POST['new'] ?? '');
            $po = $new ? resolve_file($cat, $sub, $_POST['old'] ?? '') : null;
            $pn = $new ? resolve_file($cat, $sub, $new) : null;
            if ($po && $pn && is_file($po) && !file_exists($pn)) {
                rename($po, $pn);
                log_activity($user, 'file_rename', $where . '/' . basename($_POST['old']) . ' -> ' . $new);
                $flash['ok'] = 'Rename ok.';
            } else {
                $flash['err'] = 'Rename gagal (nama ditolak / sudah ada).';
            }
        }

        if ($act === 'move') {
            $dest = norm_sub($_POST['dest'] ?? '');
            $src = resolve_file($cat, $sub, $_POST['name'] ?? '');
            $ddir = ($dest === null) ? null : resolve_dir($cat, $dest);
            if ($dest === null || !$src || !is_file($src) || $ddir === null) {
                $flash['err'] = 'Pindah gagal (file / folder tujuan tidak valid).';
            } elseif (realpath(dirname($src)) === realpath($ddir)) {
                $flash['err'] = 'File sudah di folder itu.';
            } else {
                $base = basename($src);
                $target = $ddir . '/' . $base;
                if (file_exists($target)) {
                    $pi = pathinfo($base);
                    $base = ($pi['filename'] ?? 'file') . '-' . date('His') . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
                    $target = $ddir . '/' . $base;
                }
                if (rename($src, $target)) {
                    $to = $cat . ($dest !== '' ? '/' . $dest : '');
                    log_activity($user, 'file_move', $where . '/' . basename($src) . ' -> ' . $to . '/' . $base);
                    $flash['ok'] = 'Dipindah ke ' . $to . '/' . $base;
                } else {
                    $flash['err'] = 'Pindah gagal.';
                }
            }
        }

        if ($act === 'unzip' && !empty($_FILES['zipfile'])) {
            $f = $_FILES['zipfile'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $flash['err'] = 'Upload gagal (kode ' . (int)$f['error'] . ').';
            } elseif (($f['size'] ?? 0) > MAX_UPLOAD_BYTES) {
                $flash['err'] = 'Terlalu besar. Maks ' . (MAX_UPLOAD_BYTES / 1048576) . 'MB.';
            } elseif (strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION)) !== 'zip') {
                $flash['err'] = 'Hanya file .zip.';
            } elseif (!class_exists('ZipArchive')) {
                $flash['err'] = 'Ekstensi ZIP PHP tidak tersedia.';
            } else {
                [$cat2, $sub2] = files_ctx();
                $dir = resolve_dir($cat2, $sub2);
                $where = $cat2 . ($sub2 !== '' ? '/' . $sub2 : '');
                if ($dir === null) {
                    $flash['err'] = 'Folder tujuan tidak valid.';
                } else {
                    $r = zip_extract($f['tmp_name'], $dir);
                    if ($r === null) {
                        $flash['err'] = 'Gagal membuka zip.';
                    } else {
                        log_activity($user, 'zip_extract', $where . '/' . basename($f['name'] ?? '') . " ({$r['ok']} file, {$r['skip']} dilewati)");
                        $flash['ok'] = "Diekstrak {$r['ok']} file" . ($r['skip'] ? " ({$r['skip']} dilewati: .php/path jahat)" : '') . " ke $where.";
                    }
                }
            }
        }
    }

    // Download via GET aman
    if (($_GET['dl'] ?? '') !== '') {
        [$cat, $sub] = files_ctx();
        $p = resolve_file($cat, $sub, $_GET['dl']);
        if ($p && is_file($p)) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($p));
            header('Content-Disposition: attachment; filename="' . basename($p) . '"');
            readfile($p);
            exit;
        }
    }

    // Unduh folder sebagai .zip via GET aman
    if (isset($_GET['zipdl'])) {
        [$cat, $sub] = files_ctx();
        if (!class_exists('ZipArchive')) {
            $flash['err'] = 'Ekstensi ZIP PHP tidak tersedia.';
        } else {
            $root = cat_root($cat);
            $base = resolve_dir($cat, $sub);
            $tname = trim($_GET['zipdl'] ?? '');
            if ($root === null || $base === null) {
                $flash['err'] = 'Folder tidak valid.';
            } else {
                $target = $base;
                $zname = ($sub !== '' ? basename($base) : $cat);
                if ($tname !== '') {
                    $safe = safe_dirname($tname);
                    if (!$safe || !is_dir($base . '/' . $safe)) {
                        $flash['err'] = 'Folder tidak valid.';
                        $target = null;
                    } else {
                        $target = $base . '/' . $safe;
                        $zname = $safe;
                    }
                }
                if ($target !== null) {
                    if (!zip_stream($target, $zname . '.zip')) {
                        $flash['err'] = 'Gagal membuat zip (kosong / terlalu besar / error).';
                    }
                }
            }
        }
    }

    return $flash;
}

/** Stream folder sebagai .zip; false bila gagal. Maks 2000 file / 500MB. */
function zip_stream(string $dir, string $zipname): bool {
    $rp = realpath($dir);
    if ($rp === false || !is_dir($rp)) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'dz');
    if ($tmp === false) return false;
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return false; }
    $n = 0;
    $bytes = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($n >= 2000 || $bytes >= 500 * 1024 * 1024) break;
            $p = $f->getPathname();
            if (!str_starts_with($p, $rp) || !$f->isFile() || $f->isLink()) continue;
            $local = basename($rp) . '/' . substr($p, strlen($rp) + 1);
            $z->addFile($p, $local);
            $n++;
            $bytes += $f->getSize();
        }
    } catch (Throwable $e) {
        $z->close();
        @unlink($tmp);
        return false;
    }
    if ($n === 0) { $z->close(); @unlink($tmp); return false; }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $zipname) . '"');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function files_list(string $cat, string $sub, string $q): array {
    $dirs = [];
    $rows = [];
    $dir = resolve_dir($cat, $sub);
    if ($dir === null) return [$dirs, $rows];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if ($q !== '' && stripos($f, $q) === false) continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            $dirs[] = ['name' => $f, 'mtime' => filemtime($p), 'count' => count_items($p)];
        } elseif (is_file($p)) {
            $rows[] = ['name' => $f, 'size' => filesize($p), 'mtime' => filemtime($p)];
        }
    }
    usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($rows, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return [$dirs, $rows];
}

function files_page_url(string $cat, string $sub, array $extra = []): string {
    $u = '?page=files&cat=' . $cat;
    if ($sub !== '') $u .= '&dir=' . dir_url($sub);
    foreach ($extra as $k => $v) $u .= '&' . $k . '=' . urlencode((string)$v);
    return $u;
}

/** Dropdown pindah file ke folder lain dalam kategori yang sama. */
function move_form(string $cat, string $sub, string $name, string $t, bool $icon_only): void {
    $opts = array_values(array_filter(all_dirs($cat), fn($d) => $d !== $sub));
    ?>
    <details class="move">
      <summary class="<?= $icon_only ? 'btn iconbtn' : 'btn btn-sm' ?>" title="Pindah folder"><?= icon('move') ?><?= $icon_only ? '' : 'Move' ?></summary>
      <form method="post" class="move-form">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="move">
        <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <input type="hidden" name="dir" value="<?= e($sub) ?>">
        <input type="hidden" name="name" value="<?= e($name) ?>">
        <select class="input mono" name="dest">
          <?php foreach ($opts as $d): ?>
            <option value="<?= e($d) ?>"><?= e($d === '' ? '~/' . $cat . '/ (root)' : $d) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn <?= $icon_only ? 'btn-sm' : 'btn-sm' ?> btn-primary" type="submit">OK</button>
      </form>
    </details>
    <?php
}

function files_render(string $user, array $flash): void {
    [$cat, $sub] = files_ctx();
    $q = trim($_GET['q'] ?? '');
    [$dirs, $rows] = files_list($cat, $sub, $q);
    $t = csrf_token();
    if ($flash['ok']) echo '<div class="alert alert-ok">' . e($flash['ok']) . '</div>';
    if ($flash['err']) echo '<div class="alert alert-err">' . e($flash['err']) . '</div>';

    $gallery = ($cat === 'images' || $cat === 'videos');
    $view = $_GET['view'] ?? ($gallery ? 'grid' : 'list');
    if (!in_array($view, ['grid', 'list'], true)) $view = 'list';
    if (!$gallery) $view = 'list';
    $dirparam = $sub !== '' ? '&dir=' . dir_url($sub) : '';
    $qparam = $q !== '' ? '&q=' . urlencode($q) : '';
    ?>
    <div class="toolbar">
      <noscript><style>.pane{display:block !important}#newBtn{display:none}</style></noscript>
      <div class="newmenu">
        <button class="btn btn-primary newbtn" id="newBtn" type="button">+ Baru</button>
        <div class="menu" id="newMenu" hidden>
          <button type="button" data-pane="paneFolder">Folder baru</button>
          <button type="button" data-modal="upModal">Upload file</button>
          <button type="button" data-pane="paneZip">Extract .zip</button>
        </div>
      </div>
      <form method="get" class="row" style="flex:1;justify-content:flex-end">
        <input type="hidden" name="page" value="files">
        <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <?php if ($sub !== ''): ?><input type="hidden" name="dir" value="<?= e($sub) ?>"><?php endif; ?>
        <input class="input mono" name="q" placeholder="search…" value="<?= e($q) ?>">
        <button class="btn" type="submit">Cari</button>
      </form>
    </div>
    <div class="pane" id="paneFolder" hidden>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="mkdir">
        <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <input type="hidden" name="dir" value="<?= e($sub) ?>">
        <input class="input mono" name="name" placeholder="nama-folder" required maxlength="60">
        <button class="btn btn-primary" type="submit">Buat folder</button>
      </form>
    </div>
    <div class="pane" id="paneUpload" hidden>
      <form method="post" enctype="multipart/form-data" class="row">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="upload">
        <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <input type="hidden" name="dir" value="<?= e($sub) ?>">
        <input class="input" type="file" name="file" required>
        <button class="btn btn-primary" type="submit">Upload<?= $sub !== '' ? ' ke folder ini' : ' (auto-pilah)' ?></button>
      </form>
    </div>
    <div class="pane" id="paneZip" hidden>
      <form method="post" enctype="multipart/form-data" class="row">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="unzip">
        <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <input type="hidden" name="dir" value="<?= e($sub) ?>">
        <input class="input" type="file" name="zipfile" accept=".zip" required>
        <button class="btn btn-primary" type="submit">Extract ke folder ini</button>
        <span class="muted mono" style="font-size:11px">.php & path jahat dilewati otomatis</span>
      </form>
    </div>
    <div class="upmodal" id="upModal" hidden data-cat="<?= e($cat) ?>" data-dir="<?= e($sub) ?>" data-csrf="<?= e($t) ?>">
      <div class="upbox" role="dialog" aria-modal="true" aria-label="Upload file">
        <div class="uphead">
          <h2>Upload ke <span class="mono"><?= e($sub !== '' ? $cat . '/' . $sub : $cat . ' (auto-pilah)') ?></span></h2>
          <button class="btn" id="upClose" type="button">Tutup</button>
        </div>
        <div class="updrop" id="upDrop">
          <p><strong>Seret & letakkan file di sini</strong></p>
          <p class="muted">atau</p>
          <button class="btn btn-primary" id="upBrowse" type="button">Pilih file</button>
          <input type="file" id="upInput" multiple hidden>
          <p class="muted mono" style="font-size:11px">maks 50MB/file · .php dkk ditolak server</p>
        </div>
        <div id="upList"></div>
        <div class="upfoot"><span class="mono muted" id="upSummary"></span></div>
      </div>
    </div>
    <div class="tabs">
      <?php foreach (FILE_CATS as $c): ?>
        <a href="?page=files&cat=<?= $c ?>" class="<?= $cat === $c ? 'active' : '' ?>"><?= $c ?></a>
      <?php endforeach; ?>
    </div>
    <div class="crumbs mono">
      <a href="<?= e(files_page_url($cat, '')) ?>">~/storage/<?= e($cat) ?>/</a>
      <?php
      $acc = [];
      foreach (($sub === '' ? [] : explode('/', $sub)) as $seg) {
          $acc[] = $seg;
          $path = implode('/', $acc);
          echo '<span>/</span><a href="' . e(files_page_url($cat, $path)) . '">' . e($seg) . '</a>';
      }
      ?>
    </div>
    <?php
    $pv = $_GET['preview'] ?? '';
    if ($pv !== '') files_preview($cat, $sub, $pv);
    ?>
    <div class="panel">
      <div class="panel-head"><h2><?= e($cat) ?><?= $sub !== '' ? '/' . e($sub) : '' ?> — <?= count($dirs) + count($rows) ?> item</h2>
        <span class="muted mono" style="font-size:11px">maks 50MB · .php ditolak</span>
        <span style="flex:1"></span>
        <?php if ($gallery): ?>
          <a class="btn btn-sm <?= $view === 'grid' ? 'btn-primary' : '' ?>" href="?page=files&cat=<?= e($cat) ?><?= $dirparam ?>&view=grid<?= $qparam ?>">Grid</a>
          <a class="btn btn-sm <?= $view === 'list' ? 'btn-primary' : '' ?>" href="?page=files&cat=<?= e($cat) ?><?= $dirparam ?>&view=list<?= $qparam ?>">List</a>
        <?php endif; ?>
      </div>
      <?php if ($view === 'grid'): ?>
        <?php files_grid($dirs, $rows, $cat, $sub, $t); ?>
      <?php else: ?>
      <div class="tscroll"><table>
        <thead><tr><th>Name</th><th class="num">Size</th><th>Modified</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$dirs && !$rows): ?>
          <tr><td colspan="4" class="muted">Kosong. Upload file / buat folder pertama.</td></tr>
        <?php endif; ?>
        <?php foreach ($dirs as $d): ?>
          <tr>
            <td><div class="filecell"><span class="f-icon"><?= icon('folder') ?></span><span class="nm"><a href="<?= e(files_page_url($cat, $sub === '' ? $d['name'] : $sub . '/' . $d['name'])) ?>"><?= e($d['name']) ?>/</a></span><span class="muted mono" style="font-size:11px"><?= (int)$d['count'] ?> item</span></div></td>
            <td class="num">—</td>
            <td class="mono"><?= e(date('Y-m-d H:i', (int)$d['mtime'])) ?></td>
            <td><div class="actions">
              <a class="btn btn-sm" title="Unduh folder (.zip)" href="<?= e(files_page_url($cat, $sub, ['zipdl' => $d['name']])) ?>"><?= icon('download') ?>Zip</a>
              <form method="post" class="rename-form">
                <input type="hidden" name="csrf" value="<?= e($t) ?>">
                <input type="hidden" name="act" value="rename_dir">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <input type="hidden" name="dir" value="<?= e($sub) ?>">
                <input type="hidden" name="old" value="<?= e($d['name']) ?>">
                <input class="input mono" name="new" value="<?= e($d['name']) ?>">
                <button class="btn btn-sm" type="submit"><?= icon('pencil') ?>Rename</button>
              </form>
              <form method="post" onsubmit="return confirm('Hapus folder <?= e($d['name']) ?> dan <?= (int)$d['count'] ?> item di dalamnya?')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>">
                <input type="hidden" name="act" value="rmdir">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <input type="hidden" name="dir" value="<?= e($sub) ?>">
                <input type="hidden" name="name" value="<?= e($d['name']) ?>">
                <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?>Hapus</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><div class="filecell"><span class="f-icon"><?= icon($cat) ?></span><span class="nm" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></span></div></td>
            <td class="num"><?= e(fmt_bytes((int)$r['size'])) ?></td>
            <td class="mono"><?= e(date('Y-m-d H:i', (int)$r['mtime'])) ?></td>
            <td><div class="actions">
              <a class="btn btn-sm" title="Pratinjau" href="<?= e(files_page_url($cat, $sub, ['preview' => $r['name']])) ?>"><?= icon('eye') ?>Preview</a>
              <a class="btn btn-sm" href="<?= e(files_page_url($cat, $sub, ['dl' => $r['name']])) ?>"><?= icon('download') ?>Download</a>
              <form method="post" class="rename-form">
                <input type="hidden" name="csrf" value="<?= e($t) ?>">
                <input type="hidden" name="act" value="rename">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <input type="hidden" name="dir" value="<?= e($sub) ?>">
                <input type="hidden" name="old" value="<?= e($r['name']) ?>">
                <input class="input mono" name="new" value="<?= e($r['name']) ?>">
                <button class="btn btn-sm" type="submit"><?= icon('pencil') ?>Rename</button>
              </form>
              <?php move_form($cat, $sub, $r['name'], $t, false); ?>
              <form method="post" onsubmit="return confirm('Hapus <?= e($r['name']) ?>?')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <input type="hidden" name="dir" value="<?= e($sub) ?>">
                <input type="hidden" name="name" value="<?= e($r['name']) ?>">
                <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?>Hapus</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php
}

/** Panel pratinjau file: pdf/gambar/video inline, teks sebagai <pre>, lainnya info+download. */
function files_preview(string $cat, string $sub, string $name): void {
    $back = files_page_url($cat, $sub);
    $p = resolve_file($cat, $sub, $name);
    if (!$p || !is_file($p)) {
        echo '<div class="alert alert-err">File tidak ditemukan. <a href="' . e($back) . '">Kembali</a></div>';
        return;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $size = filesize($p);
    $dl = files_page_url($cat, $sub, ['dl' => basename($p)]);
    ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>Preview — <?= e(basename($p)) ?></h2>
      <span class="muted mono" style="font-size:11px"><?= e(fmt_bytes((int)$size)) ?></span>
      <span style="flex:1"></span>
      <a class="btn btn-sm" href="<?= e($back) ?>">Tutup</a>
      <a class="btn btn-sm" href="<?= e($dl) ?>">Download</a></div>
      <div style="padding:16px">
      <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'], true)): ?>
        <img src="<?= e(storage_url($cat, $sub, basename($p))) ?>" alt="" style="max-width:100%;border:1px solid var(--border);border-radius:8px">
      <?php elseif (in_array($ext, ['mp4', 'webm', 'mov'], true)): ?>
        <video src="<?= e(storage_url($cat, $sub, basename($p))) ?>" controls style="max-width:100%;border:1px solid var(--border);border-radius:8px"></video>
      <?php elseif ($ext === 'pdf'): ?>
        <embed src="<?= e(storage_url($cat, $sub, basename($p))) ?>" type="application/pdf" style="width:100%;height:70vh;border:1px solid var(--border);border-radius:8px">
      <?php elseif (in_array($ext, TEXT_PREVIEW, true) && $size <= 300 * 1024): ?>
        <pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:8px;padding:14px;overflow:auto;max-height:70vh;font-size:12px;margin:0"><?= e(file_get_contents($p) ?: '') ?></pre>
      <?php else: ?>
        <div class="alert alert-info">Tidak bisa pratinjau tipe ini di browser. Silakan download.</div>
      <?php endif; ?>
      </div>
    </div>
    <?php
}

/** Tampilan galeri ala Drive untuk images & videos (folder dulu, baru file). */
function files_grid(array $dirs, array $rows, string $cat, string $sub, string $t): void {
    $base = 'storage/' . $cat . ($sub !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $sub))) : '');
    if (!$dirs && !$rows) {
        echo '<div style="padding:26px" class="muted">Kosong. Upload file / buat folder pertama.</div>';
        return;
    }
    if ($dirs) {
        echo '<div class="grid-label">Folders</div><div class="grid">';
        foreach ($dirs as $d) {
            $name = $d['name'];
            $open = files_page_url($cat, $sub === '' ? $name : $sub . '/' . $name);
            ?>
            <div class="card">
              <a class="thumb folder" href="<?= e($open) ?>"><?= icon('folder') ?></a>
              <div class="card-foot">
                <div class="nm" title="<?= e($name) ?>"><?= e($name) ?></div>
                <div class="meta"><?= (int)$d['count'] ?> item</div>
                <div class="card-actions">
                  <a class="btn iconbtn" title="Unduh folder (.zip)" href="<?= e(files_page_url($cat, $sub, ['zipdl' => $name])) ?>"><?= icon('download') ?></a>
                  <details class="rename">
                    <summary class="btn iconbtn" title="Rename"><?= icon('pencil') ?></summary>
                    <form method="post" class="rename-form">
                      <input type="hidden" name="csrf" value="<?= e($t) ?>">
                      <input type="hidden" name="act" value="rename_dir">
                      <input type="hidden" name="cat" value="<?= e($cat) ?>">
                      <input type="hidden" name="dir" value="<?= e($sub) ?>">
                      <input type="hidden" name="old" value="<?= e($name) ?>">
                      <input class="input mono" name="new" value="<?= e($name) ?>">
                      <button class="btn btn-sm btn-primary" type="submit">OK</button>
                    </form>
                  </details>
                  <form method="post" onsubmit="return confirm('Hapus folder <?= e($name) ?> dan <?= (int)$d['count'] ?> item di dalamnya?')">
                    <input type="hidden" name="csrf" value="<?= e($t) ?>">
                    <input type="hidden" name="act" value="rmdir">
                    <input type="hidden" name="cat" value="<?= e($cat) ?>">
                    <input type="hidden" name="dir" value="<?= e($sub) ?>">
                    <input type="hidden" name="name" value="<?= e($name) ?>">
                    <button class="btn iconbtn btn-danger" title="Hapus" type="submit"><?= icon('trash') ?></button>
                  </form>
                </div>
              </div>
            </div>
            <?php
        }
        echo '</div>';
    }
    if ($rows) {
        if ($dirs) echo '<div class="grid-label">Files</div>';
        echo '<div class="grid">';
        foreach ($rows as $r) {
            $name = $r['name'];
            $src = $base . '/' . rawurlencode($name);
            $dl = files_page_url($cat, $sub, ['dl' => $name]);
            $meta = fmt_bytes((int)$r['size']) . ' · ' . date('Y-m-d H:i', (int)$r['mtime']);
            ?>
            <div class="card">
              <div class="thumb">
                <?php if ($cat === 'images'): ?>
                  <img src="<?= e($src) ?>" alt="<?= e($name) ?>" loading="lazy">
                <?php else: ?>
                  <video src="<?= e($src) ?>" preload="metadata" playsinline></video>
                  <button class="vplay" type="button" aria-label="Play <?= e($name) ?>"><?= icon('play') ?></button>
                <?php endif; ?>
              </div>
              <div class="card-foot">
                <div class="nm" title="<?= e($name) ?>"><?= e($name) ?></div>
                <div class="meta"><?= e($meta) ?></div>
                <div class="card-actions">
                  <a class="btn iconbtn" title="Download" href="<?= e($dl) ?>"><?= icon('download') ?></a>
                  <details class="rename">
                    <summary class="btn iconbtn" title="Rename"><?= icon('pencil') ?></summary>
                    <form method="post" class="rename-form">
                      <input type="hidden" name="csrf" value="<?= e($t) ?>">
                      <input type="hidden" name="act" value="rename">
                      <input type="hidden" name="cat" value="<?= e($cat) ?>">
                      <input type="hidden" name="dir" value="<?= e($sub) ?>">
                      <input type="hidden" name="old" value="<?= e($name) ?>">
                      <input class="input mono" name="new" value="<?= e($name) ?>">
                      <button class="btn btn-sm btn-primary" type="submit">OK</button>
                    </form>
                  </details>
                  <?php move_form($cat, $sub, $name, $t, true); ?>
                  <form method="post" onsubmit="return confirm('Hapus <?= e($name) ?>?')">
                    <input type="hidden" name="csrf" value="<?= e($t) ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="cat" value="<?= e($cat) ?>">
                    <input type="hidden" name="dir" value="<?= e($sub) ?>">
                    <input type="hidden" name="name" value="<?= e($name) ?>">
                    <button class="btn iconbtn btn-danger" title="Hapus" type="submit"><?= icon('trash') ?></button>
                  </form>
                </div>
              </div>
            </div>
            <?php
        }
        echo '</div>';
    }
}
