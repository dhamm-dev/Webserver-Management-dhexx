<?php
// Modul Databases: koneksi MySQL + CRUD DB/tabel/baris + import/export.
declare(strict_types=1);

const COL_TYPES = [
    'INT', 'BIGINT', 'TINYINT', 'SMALLINT', 'MEDIUMINT',
    'VARCHAR', 'CHAR', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT',
    'DATETIME', 'TIMESTAMP', 'DATE', 'TIME',
    'DECIMAL', 'FLOAT', 'DOUBLE', 'BOOLEAN', 'JSON', 'BLOB',
];
const COL_LEN_TYPES = ['INT', 'BIGINT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'VARCHAR', 'CHAR'];

/** Ambil kolom tabel + kolom PK (PRI lebih dulu). */
function table_cols(PDO $pdo, string $db, string $table): array {
    $pdo->exec("USE " . esc_ident($db));
    $cols = $pdo->query("SHOW COLUMNS FROM " . esc_ident($table))->fetchAll(PDO::FETCH_ASSOC);
    $fields = array_column($cols, 'Field');
    $pk = '';
    foreach ($cols as $c) {
        if (($c['Key'] ?? '') === 'PRI') { $pk = $c['Field']; break; }
    }
    if ($pk === '' && $fields) $pk = $fields[0];
    return [$cols, $fields, $pk];
}

/** Bangun definisi kolom CREATE TABLE dari input form; null bila ditolak. */
function build_col_def(array $c): ?string {
    $name = $c['name'] ?? '';
    $type = strtoupper(trim($c['type'] ?? ''));
    if (!valid_ident($name) || !in_array($type, COL_TYPES, true)) return null;
    if ($type === 'BOOLEAN') $type = 'TINYINT';
    $def = esc_ident($name) . ' ' . $type;
    if (in_array($type, COL_LEN_TYPES, true)) {
        $len = (int)($c['len'] ?? 0);
        if ($len === 0) {
            if ($type === 'VARCHAR') $len = 255;
            elseif ($type === 'CHAR') $len = 1;
        }
        if ($len > 1024) return null;
        if ($len >= 1) $def .= "($len)";
        // integer tanpa panjang valid di MySQL 8
    } elseif ($type === 'DECIMAL') {
        $md = trim($c['len'] ?? '');
        if ($md === '') $md = '10,2';
        if (!preg_match('/^\d{1,3},\d{1,3}$/', $md)) return null;
        $def .= "($md)";
    }
    $keyHint = strtoupper(trim($c['key'] ?? ''));
    $isAi = strtoupper(trim($c['extra'] ?? '')) === 'AUTO_INCREMENT';
    // PRIMARY KEY wajib NOT NULL (MySQL error 1171) — paksa seperti phpMyAdmin.
    $nullable = ($c['null'] ?? 'YES') === 'YES' && $keyHint !== 'PRIMARY' && !$isAi;
    $def .= $nullable ? ' NULL' : ' NOT NULL';
    $df = trim($c['def'] ?? '');
    if ($df !== '') {
        if (preg_match('/^NULL$/i', $df)) $def .= ' DEFAULT NULL';
        elseif (preg_match('/^CURRENT_TIMESTAMP$/i', $df)) $def .= ' DEFAULT CURRENT_TIMESTAMP';
        elseif (is_numeric($df)) $def .= ' DEFAULT ' . $df;
        else $def .= ' DEFAULT ' . "'" . str_replace("'", "''", $df) . "'";
    }
    $extra = strtoupper(trim($c['extra'] ?? ''));
    if ($extra === 'AUTO_INCREMENT') {
        if (!in_array($type, ['INT', 'BIGINT', 'TINYINT', 'SMALLINT', 'MEDIUMINT'], true)) return null;
        $def .= ' AUTO_INCREMENT';
    }
    return $def;
}

/** Pecah file .sql jadi statement (hormati string + komentar). Tanpa DELIMITER/procedure. */
function split_sql(string $sql): array {
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $q = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($q !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
            if ($ch === $q) {
                if ($ch === "'" && ($sql[$i + 1] ?? '') === "'") { $buf .= $sql[++$i]; continue; }
                $q = null;
            }
            continue;
        }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? ' ', [" ", "\t", "\n", "\r"], true)) {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buf .= "\n";
            continue;
        }
        if ($ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buf .= "\n";
            continue;
        }
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
            $i += 2;
            while ($i < $len && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) $i++;
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $buf .= $ch; continue; }
        if ($ch === ';') {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $t = trim($buf);
    if ($t !== '') $stmts[] = $t;
    return $stmts;
}

/** Flash message lintas redirect (PRG). */
function set_flash(string $kind, string $msg): void {
    start_session();
    $_SESSION['flash'] = ['kind' => $kind, 'msg' => $msg];
}

function take_flash(): array {
    start_session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return ['ok' => '', 'err' => ''];
    return $f['kind'] === 'err' ? ['ok' => '', 'err' => $f['msg']] : ['ok' => $f['msg'], 'err' => ''];
}

function db_redirect(string $url, string $kind, string $msg): void {
    set_flash($kind, $msg);
    header('Location: ' . $url);
    exit;
}

function databases_handle(string $user): array {
    $flash = ['ok' => '', 'err' => ''];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $flash;
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'connect') {
        start_session();
        $_SESSION['mysql'] = [
            'host' => trim($_POST['host'] ?? '127.0.0.1'),
            'port' => (int)($_POST['port'] ?? 3306) ?: 3306,
            'user' => trim($_POST['user'] ?? ''),
            'pass' => (string)($_POST['pass'] ?? ''),
        ];
        $pdo = mysql_conn();
        if ($pdo) { log_activity($user, 'db_connect', $_SESSION['mysql']['host']); $flash['ok'] = 'Terhubung ke MySQL.'; }
        else $flash['err'] = 'Gagal: ' . ($_SESSION['mysql_error'] ?? 'unknown');
        return $flash;
    }
    if ($act === 'disconnect') {
        unset($_SESSION['mysql']);
        $flash['ok'] = 'Override dihapus, kembali ke auto-connect.';
        return $flash;
    }

    $pdo = mysql_conn();
    if (!$pdo) { $flash['err'] = 'Gagal terhubung (auto maupun manual): ' . ($_SESSION['mysql_error'] ?? 'unknown'); return $flash; }
    try {
        if ($act === 'create_db' && valid_ident($_POST['name'] ?? '')) {
            $pdo->exec("CREATE DATABASE " . esc_ident($_POST['name']) . " CHARACTER SET utf8mb4");
            log_activity($user, 'db_create', $_POST['name']);
            db_redirect('?page=databases&db=' . urlencode($_POST['name']), 'ok', 'Database dibuat.');
        } elseif ($act === 'drop_db' && valid_ident($_POST['name'] ?? '')) {
            $pdo->exec("DROP DATABASE " . esc_ident($_POST['name']));
            log_activity($user, 'db_drop', $_POST['name']);
            db_redirect('?page=databases', 'ok', 'Database dihapus.');
        } elseif ($act === 'create_table' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '')) {
            $db = $_POST['db'];
            $table = $_POST['table'];
            $names = (array)($_POST['cname'] ?? []);
            if (count($names) > 32) throw new RuntimeException('Maks 32 kolom.');
            $defs = [];
            $keys = [];
            foreach ($names as $i => $nm) {
                if (trim((string)$nm) === '') continue;
                $d = build_col_def([
                    'name' => $nm,
                    'type' => $_POST['ctype'][$i] ?? '',
                    'len' => $_POST['clen'][$i] ?? '',
                    'null' => $_POST['cnull'][$i] ?? 'YES',
                    'key' => $_POST['ckey'][$i] ?? '',
                    'def' => $_POST['cdef'][$i] ?? '',
                    'extra' => $_POST['cextra'][$i] ?? '',
                ]);
                if ($d === null) throw new RuntimeException('Definisi kolom ke-' . ($i + 1) . ' tidak valid.');
                $defs[] = $d;
                $k = strtoupper(trim($_POST['ckey'][$i] ?? ''));
                $cn = (string)$nm;
                if (($cExtra = strtoupper(trim($_POST['cextra'][$i] ?? ''))) === 'AUTO_INCREMENT') $k = 'PRIMARY';
                if ($k === 'PRIMARY') $keys['pri'][] = $cn;
                elseif ($k === 'UNIQUE') $keys['uq'][] = $cn;
                elseif ($k === 'INDEX') $keys['ix'][] = $cn;
                elseif ($k !== '') throw new RuntimeException('Key kolom ke-' . ($i + 1) . ' tidak valid.');
            }
            if (!$defs) throw new RuntimeException('Minimal 1 kolom.');
            if (!empty($keys['pri'])) $defs[] = 'PRIMARY KEY (' . implode(',', array_map('esc_ident', array_unique($keys['pri']))) . ')';
            foreach ($keys['uq'] ?? [] as $c) $defs[] = 'UNIQUE KEY ' . esc_ident('uq_' . $c) . ' (' . esc_ident($c) . ')';
            foreach ($keys['ix'] ?? [] as $c) $defs[] = 'KEY ' . esc_ident('ix_' . $c) . ' (' . esc_ident($c) . ')';
            $pdo->exec("USE " . esc_ident($db));
            $pdo->exec("CREATE TABLE " . esc_ident($table) . " (\n  " . implode(",\n  ", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            log_activity($user, 'db_table_create', "$db.$table");
            db_redirect('?page=databases&db=' . urlencode($db) . '&table=' . urlencode($table), 'ok', "Tabel $table dibuat.");
        } elseif ($act === 'drop_table' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '')) {
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $pdo->exec("DROP TABLE " . esc_ident($_POST['table']));
            log_activity($user, 'db_table_drop', $_POST['db'] . '.' . $_POST['table']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']), 'ok', 'Tabel dihapus.');
        } elseif ($act === 'truncate_table' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '')) {
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $pdo->exec("TRUNCATE TABLE " . esc_ident($_POST['table']));
            log_activity($user, 'db_table_truncate', $_POST['db'] . '.' . $_POST['table']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']) . '&table=' . urlencode($_POST['table']), 'ok', 'Tabel dikosongkan.');
        } elseif ($act === 'rename_table' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['old'] ?? '') && valid_ident($_POST['new'] ?? '')) {
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $pdo->exec("RENAME TABLE " . esc_ident($_POST['old']) . " TO " . esc_ident($_POST['new']));
            log_activity($user, 'db_table_rename', $_POST['db'] . '.' . $_POST['old'] . ' -> ' . $_POST['new']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']) . '&table=' . urlencode($_POST['new']), 'ok', 'Tabel di-rename.');
        } elseif ($act === 'insert_row' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '')) {
            [$cols, $fields] = table_cols($pdo, $_POST['db'], $_POST['table']);
            $setCols = [];
            $vals = [];
            foreach ($fields as $f) {
                if (!valid_ident($f)) continue;
                if (isset($_POST['n_' . $f])) { $setCols[] = $f; $vals[] = null; }
                elseif (array_key_exists('f_' . $f, $_POST)) { $setCols[] = $f; $vals[] = (string)$_POST['f_' . $f]; }
            }
            if (!$setCols) throw new RuntimeException('Tidak ada data.');
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $st = $pdo->prepare("INSERT INTO " . esc_ident($_POST['table']) . " (" . implode(',', array_map('esc_ident', $setCols)) . ") VALUES (" . implode(',', array_fill(0, count($setCols), '?')) . ")");
            $st->execute($vals);
            log_activity($user, 'db_row_insert', $_POST['db'] . '.' . $_POST['table']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']) . '&table=' . urlencode($_POST['table']), 'ok', 'Baris ditambah.');
        } elseif ($act === 'update_row' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '') && valid_ident($_POST['pk'] ?? '')) {
            [$cols, $fields] = table_cols($pdo, $_POST['db'], $_POST['table']);
            $pk = $_POST['pk'];
            if (!in_array($pk, $fields, true)) throw new RuntimeException('PK tidak valid.');
            $set = [];
            $vals = [];
            foreach ($fields as $f) {
                if ($f === $pk || !valid_ident($f)) continue;
                if (isset($_POST['n_' . $f])) { $set[] = esc_ident($f) . '=?'; $vals[] = null; }
                elseif (array_key_exists('f_' . $f, $_POST)) { $set[] = esc_ident($f) . '=?'; $vals[] = (string)$_POST['f_' . $f]; }
            }
            if (!$set) throw new RuntimeException('Tidak ada perubahan.');
            $vals[] = (string)($_POST['pkv'] ?? '');
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $st = $pdo->prepare("UPDATE " . esc_ident($_POST['table']) . " SET " . implode(',', $set) . " WHERE " . esc_ident($pk) . "=? LIMIT 1");
            $st->execute($vals);
            log_activity($user, 'db_row_update', $_POST['db'] . '.' . $_POST['table']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']) . '&table=' . urlencode($_POST['table']), 'ok', 'Baris diupdate.');
        } elseif ($act === 'delete_row' && valid_ident($_POST['db'] ?? '') && valid_ident($_POST['table'] ?? '')) {
            $col = $_POST['col'] ?? '';
            $val = $_POST['val'] ?? '';
            if (!valid_ident($col)) throw new RuntimeException('Kolom tidak valid.');
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $st = $pdo->prepare("DELETE FROM " . esc_ident($_POST['table']) . " WHERE " . esc_ident($col) . " = ? LIMIT 1");
            $st->execute([$val]);
            log_activity($user, 'db_row_delete', $_POST['db'] . '.' . $_POST['table']);
            db_redirect('?page=databases&db=' . urlencode($_POST['db']) . '&table=' . urlencode($_POST['table']), 'ok', 'Baris dihapus.');
        } elseif ($act === 'import_sql' && valid_ident($_POST['db'] ?? '') && !empty($_FILES['sqlfile'])) {
            $f = $_FILES['sqlfile'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal.');
            if (($f['size'] ?? 0) > MAX_UPLOAD_BYTES) throw new RuntimeException('File > 50MB.');
            if (strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION)) !== 'sql') throw new RuntimeException('Hanya file .sql.');
            $sql = file_get_contents($f['tmp_name']);
            if ($sql === false || trim($sql) === '') throw new RuntimeException('File kosong.');
            $stmts = split_sql($sql);
            if (count($stmts) > 2000) throw new RuntimeException('Terlalu banyak statement (>2000).');
            $pdo->exec("USE " . esc_ident($_POST['db']));
            $ok = 0;
            $errs = [];
            foreach ($stmts as $i => $s) {
                try { $pdo->exec($s); $ok++; }
                catch (Throwable $e) { if (count($errs) < 5) $errs[] = '#' . ($i + 1) . ': ' . $e->getMessage(); }
            }
            log_activity($user, 'db_import', $_POST['db'] . " ($ok/" . count($stmts) . ' ok)');
            db_redirect('?page=databases&db=' . urlencode($_POST['db']), 'ok', "Import: $ok/" . count($stmts) . ' statement ok.' . ($errs ? ' Gagal: ' . implode(' | ', $errs) : ''));
        } elseif ($act === 'sql' && trim($_POST['q'] ?? '') !== '') {
            $q = trim($_POST['q']);
            if (!validate_read_query($q)) {
                $flash['err'] = 'Ditolak: hanya SATU query SELECT/SHOW/DESCRIBE/EXPLAIN tanpa ; komentar, atau INTO/OUTFILE/LOAD.';
            } else {
                $_SESSION['last_result'] = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
                $flash['ok'] = count($_SESSION['last_result']) . ' baris.';
            }
        } else {
            $flash['err'] = 'Aksi tidak dikenal / nama tidak valid (huruf, angka, _).';
        }
    } catch (Throwable $e) {
        $flash['err'] = 'MySQL: ' . $e->getMessage();
    }
    return $flash;
}

/** Input form untuk satu kolom baris (tambah/edit). */
function row_field(array $col, $val, string $t): void {
    $f = $col['Field'];
    $type = strtoupper($col['Type'] ?? '');
    $isText = str_contains($type, 'TEXT') || str_contains($type, 'BLOB') || str_contains($type, 'JSON');
    $v = $val === null ? '' : (string)$val;
    ?>
    <div><label class="lbl"><?= e($f) ?> <span style="text-transform:none">(<?= e($col['Type'] ?? '') ?><?= ($col['Null'] ?? '') === 'YES' ? ', null ok' : '' ?>)</span></label>
    <?php if ($isText): ?>
      <textarea class="input mono" style="width:100%" name="f_<?= e($f) ?>" rows="2"><?= e($v) ?></textarea>
    <?php else: ?>
      <input class="input mono" style="width:100%" name="f_<?= e($f) ?>" value="<?= e($v) ?>">
    <?php endif; ?>
    <?php if (($col['Null'] ?? '') === 'YES'): ?>
      <label class="mono" style="font-size:11px;color:var(--muted)"><input type="checkbox" name="n_<?= e($f) ?>" <?= $val === null ? 'checked' : '' ?>> NULL</label>
    <?php endif; ?>
    </div>
    <?php
}

function databases_render(array $flash): void {
    $sf = take_flash();
    if ($sf['ok'] !== '') $flash['ok'] = $sf['ok'];
    if ($sf['err'] !== '') $flash['err'] = $sf['err'];
    $t = csrf_token();
    if ($flash['ok']) echo '<div class="alert alert-ok">' . e($flash['ok']) . '</div>';
    if ($flash['err']) echo '<div class="alert alert-err">' . e($flash['err']) . '</div>';
    start_session();
    $c = $_SESSION['mysql'] ?? null;
    $autoLabel = 'auto: ' . MYSQL_USER . '@' . MYSQL_HOST;
    ?>
    <details class="panel" style="margin-bottom:14px"><summary class="panel-head" style="cursor:pointer;list-style:none"><h2>Koneksi (<?= $c ? e($c['user'] . '@' . $c['host']) . ' — override' : e($autoLabel) ?>)</h2>
      <span style="margin-left:auto;display:flex;gap:8px;align-items:center"><a class="btn btn-sm" href="<?= e(PHPMYADMIN_URL) ?>" target="_blank" rel="noopener">Open phpMyAdmin</a></span></summary>
      <form method="post" style="padding:16px" class="form-grid">
        <input type="hidden" name="csrf" value="<?= e($t) ?>">
        <input type="hidden" name="act" value="connect">
        <div><label class="lbl">Host</label><input class="input mono" style="width:100%" name="host" value="<?= e($c['host'] ?? '127.0.0.1') ?>"></div>
        <div><label class="lbl">Port</label><input class="input mono" style="width:100%" name="port" value="<?= e((string)($c['port'] ?? 3306)) ?>"></div>
        <div><label class="lbl">User</label><input class="input mono" style="width:100%" name="user" value="<?= e($c['user'] ?? 'root') ?>"></div>
        <div><label class="lbl">Password</label><input class="input" style="width:100%" type="password" name="pass" placeholder="<?= $c ? '(tersimpan di sesi)' : '' ?>"></div>
        <div class="row" style="align-items:end">
          <button class="btn btn-primary" type="submit">Pakai kredensial ini</button>
        </div>
      </form>
      <?php if ($c): ?>
      <form method="post" style="padding:0 16px 16px"><input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="disconnect">
      <button class="btn" type="submit">Kembali ke auto</button></form>
      <?php endif; ?>
    </details>
    <?php
    $pdo = mysql_conn();
    if (!$pdo) { echo '<div class="alert alert-err">Gagal terhubung: ' . e($_SESSION['mysql_error'] ?? 'unknown') . '</div>'; return; }
    try {
        $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $dbs = array_values(array_filter($dbs, fn($d) => !in_array($d, ['information_schema','performance_schema','sys'], true)));
    } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
    $selDb = $_GET['db'] ?? '';
    $selTable = $_GET['table'] ?? '';
    $view = $_GET['view'] ?? 'browse';
    ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><?= count($dbs) ?> database</h2></div>
      <div style="padding:16px" class="row">
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="create_db">
          <input class="input mono" name="name" placeholder="nama_db_baru" pattern="[A-Za-z0-9_]{1,64}" required>
          <button class="btn" type="submit">Create DB</button>
        </form>
      </div>
      <div class="tscroll"><table>
        <thead><tr><th>Database</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dbs as $d): ?>
          <tr>
            <td class="mono"><a href="?page=databases&db=<?= urlencode($d) ?>"><?= e($d) ?></a></td>
            <td><div class="row">
              <a class="btn btn-sm" href="?page=databases&db=<?= urlencode($d) ?>">Browse</a>
              <a class="btn btn-sm" href="?page=databases&export=<?= urlencode($d) ?>">Export .sql</a>
              <form method="post" onsubmit="return confirm('DROP <?= e($d) ?>? Tidak bisa undo!')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="drop_db">
                <input type="hidden" name="name" value="<?= e($d) ?>">
                <button class="btn btn-sm btn-danger" type="submit">Drop</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php
    if ($selDb === '' || !valid_ident($selDb)) return;
    if (!in_array($selDb, $dbs, true)) {
        echo '<div class="alert alert-info">Database ' . e($selDb) . ' tidak ada (mungkin sudah dihapus). <a href="?page=databases">Kembali ke daftar</a></div>';
        return;
    }
    try {
        $tables = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($selDb))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
    $tableNames = array_column($tables, 'TABLE_NAME');
    ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><?= e($selDb) ?> — <?= count($tables) ?> tabel</h2>
      <span style="flex:1"></span>
      <a class="btn btn-sm" href="?page=databases&db=<?= urlencode($selDb) ?>&view=newtable">+ Tabel</a></div>
      <div class="tscroll"><table>
        <thead><tr><th>Tabel</th><th class="num">Baris ±</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$tables): ?><tr><td colspan="3" class="muted">Belum ada tabel. Klik + Tabel.</td></tr><?php endif; ?>
        <?php foreach ($tables as $tr): $tb = $tr['TABLE_NAME']; ?>
          <tr>
            <td class="mono"><a href="?page=databases&db=<?= urlencode($selDb) ?>&table=<?= urlencode($tb) ?>"><?= e($tb) ?></a></td>
            <td class="num"><?= e(number_format((int)$tr['TABLE_ROWS'])) ?></td>
            <td><div class="row">
              <a class="btn btn-sm" href="?page=databases&db=<?= urlencode($selDb) ?>&table=<?= urlencode($tb) ?>">Browse</a>
              <a class="btn btn-sm" href="?page=databases&db=<?= urlencode($selDb) ?>&table=<?= urlencode($tb) ?>&view=insert">+ Baris</a>
              <a class="btn btn-sm" href="?page=databases&db=<?= urlencode($selDb) ?>&table=<?= urlencode($tb) ?>&view=struct">Struktur</a>
              <form method="post" class="row" onsubmit="return confirm('Rename <?= e($tb) ?>?')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="rename_table">
                <input type="hidden" name="db" value="<?= e($selDb) ?>">
                <input type="hidden" name="old" value="<?= e($tb) ?>">
                <input class="input mono" style="width:130px;padding:4px 8px;font-size:12px" name="new" value="<?= e($tb) ?>" pattern="[A-Za-z0-9_]{1,64}" required>
                <button class="btn btn-sm" type="submit">Rename</button>
              </form>
              <form method="post" onsubmit="return confirm('Kosongkan <?= e($tb) ?> (TRUNCATE)?')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="truncate_table">
                <input type="hidden" name="db" value="<?= e($selDb) ?>">
                <input type="hidden" name="table" value="<?= e($tb) ?>">
                <button class="btn btn-sm btn-danger" type="submit">Truncate</button>
              </form>
              <form method="post" onsubmit="return confirm('DROP TABLE <?= e($tb) ?>? Tidak bisa undo!')">
                <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="drop_table">
                <input type="hidden" name="db" value="<?= e($selDb) ?>">
                <input type="hidden" name="table" value="<?= e($tb) ?>">
                <button class="btn btn-sm btn-danger" type="submit">Drop</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php if ($view === 'newtable'): ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>Tabel baru di <?= e($selDb) ?></h2></div>
      <form method="post" style="padding:16px" id="newTableForm">
        <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="create_table">
        <input type="hidden" name="db" value="<?= e($selDb) ?>">
        <div class="row" style="margin-bottom:12px">
          <div><label class="lbl">Nama tabel</label><input class="input mono" name="table" pattern="[A-Za-z0-9_]{1,64}" required placeholder="nama_tabel"></div>
          <button class="btn btn-primary" type="submit" style="align-self:end">Buat tabel</button>
        </div>
        <div class="tscroll"><table id="colTable">
          <thead><tr><th>Nama</th><th>Tipe</th><th>Pjg</th><th>Null?</th><th>Key</th><th>Default</th><th>Extra</th><th></th></tr></thead>
          <tbody></tbody>
        </table></div>
        <div class="row" style="margin-top:10px"><button class="btn" type="button" id="addCol">+ Kolom</button>
        <span class="muted mono" style="font-size:11px">Pjg: VARCHAR/INT, kosongkan untuk TEXT/dll · AUTO_INCREMENT otomatis jadi PRIMARY</span></div>
      </form>
      <script>
      (function () {
        var types = <?= json_encode(COL_TYPES) ?>;
        var tb = document.querySelector('#colTable tbody');
        function row() {
          var tr = document.createElement('tr');
          var opts = types.map(function (x) { return '<option' + (x === 'VARCHAR' ? ' selected' : '') + '>' + x + '</option>'; }).join('');
          tr.innerHTML =
            '<td><input class="input mono" name="cname[]" required maxlength="64" style="width:130px"></td>' +
            '<td><select class="input mono" name="ctype[]">' + opts + '</select></td>' +
            '<td><input class="input mono" name="clen[]" placeholder="255" style="width:70px"></td>' +
            '<td><select class="input" name="cnull[]"><option>YES</option><option>NO</option></select></td>' +
            '<td><select class="input" name="ckey[]"><option value="">-</option><option>PRIMARY</option><option>UNIQUE</option><option>INDEX</option></select></td>' +
            '<td><input class="input mono" name="cdef[]" placeholder="(kosong)" style="width:110px"></td>' +
            '<td><select class="input" name="cextra[]"><option value="">-</option><option>AUTO_INCREMENT</option></select></td>' +
            '<td><button class="btn btn-sm btn-danger" type="button">×</button></td>';
          tr.querySelector('button').addEventListener('click', function () { tr.remove(); });
          tb.appendChild(tr);
        }
        document.getElementById('addCol').addEventListener('click', function () {
          if (tb.rows.length >= 32) return;
          row();
        });
        row(); row();
      })();
      </script>
    </div>
    <?php endif; ?>
    <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>Import .sql ke <?= e($selDb) ?></h2></div>
      <form method="post" enctype="multipart/form-data" style="padding:16px" class="row">
        <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="import_sql">
        <input type="hidden" name="db" value="<?= e($selDb) ?>">
        <input class="input" type="file" name="sqlfile" accept=".sql" required>
        <button class="btn" type="submit">Import</button>
        <span class="muted mono" style="font-size:11px">maks 50MB · tanpa stored procedure</span>
      </form>
    </div>
    <?php
    if ($selTable !== '' && !in_array($selTable, $tableNames, true)) {
        echo '<div class="alert alert-info">Tabel ' . e($selTable) . ' tidak ada di ' . e($selDb) . ' (mungkin sudah dihapus/rename). <a href="?page=databases&db=' . urlencode($selDb) . '">Kembali</a></div>';
        return;
    }
    if ($selTable === '' || !valid_ident($selTable)) {
        // Query bebas read-only
        ?>
        <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Query (read-only)</h2></div>
          <form method="post" style="padding:16px">
            <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="sql">
            <input class="input mono" style="width:100%" name="q" placeholder="SELECT * FROM <?= e($selDb) ?>.tabel LIMIT 20" required>
            <div class="row" style="margin-top:10px"><button class="btn" type="submit">Run</button>
            <span class="muted mono" style="font-size:11px">hanya SELECT / SHOW / DESCRIBE / EXPLAIN</span></div>
          </form>
          <?php if (!empty($_SESSION['last_result'])): ?>
          <div class="tscroll"><table><thead><tr>
            <?php foreach (array_keys($_SESSION['last_result'][0]) as $h): ?><th><?= e($h) ?></th><?php endforeach; ?>
          </tr></thead><tbody>
            <?php foreach ($_SESSION['last_result'] as $r): ?><tr>
              <?php foreach ($r as $v): ?><td class="mono"><?= e(is_scalar($v) ? (string)$v : json_encode($v)) ?></td><?php endforeach; ?>
            </tr><?php endforeach; ?>
          </tbody></table></div>
          <?php unset($_SESSION['last_result']); endif; ?>
        </div>
        <?php
        return;
    }
    try {
        [$cols, $fields, $pk] = table_cols($pdo, $selDb, $selTable);
    } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
    $base = '?page=databases&db=' . urlencode($selDb) . '&table=' . urlencode($selTable);

    if ($view === 'struct') {
        try {
            $create = $pdo->query("SHOW CREATE TABLE " . esc_ident($selTable))->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
        ?>
        <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>Struktur <?= e($selTable) ?></h2>
          <span style="flex:1"></span><a class="btn btn-sm" href="<?= e($base) ?>">Browse</a></div>
          <div class="tscroll"><table>
            <thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>
            <?php foreach ($cols as $c): ?>
              <tr><td class="mono"><?= e($c['Field']) ?></td><td class="mono"><?= e($c['Type']) ?></td>
              <td class="mono"><?= e($c['Null']) ?></td><td class="mono"><?= e($c['Key']) ?></td>
              <td class="mono"><?= e((string)($c['Default'] ?? '')) ?></td><td class="mono"><?= e($c['Extra']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
          <div style="padding:16px"><label class="lbl">SHOW CREATE TABLE</label>
            <pre class="mono" style="background:var(--wash);border:1px solid var(--border);border-radius:6px;padding:12px;overflow-x:auto;font-size:12px"><?= e($create['Create Table'] ?? '') ?></pre></div>
        </div>
        <?php
        return;
    }

    if ($view === 'insert' || ($view === 'edit' && isset($_GET['pk'], $_GET['pkv']))) {
        $isEdit = ($view === 'edit');
        $pkCol = $isEdit ? (string)$_GET['pk'] : $pk;
        $pkVal = $isEdit ? (string)$_GET['pkv'] : '';
        if (!in_array($pkCol, $fields, true)) { echo '<div class="alert alert-err">PK tidak valid.</div>'; return; }
        $rowVals = array_fill_keys($fields, null);
        if ($isEdit) {
            try {
                $st = $pdo->prepare("SELECT * FROM " . esc_ident($selTable) . " WHERE " . esc_ident($pkCol) . "=? LIMIT 1");
                $st->execute([$pkVal]);
                $found = $st->fetch(PDO::FETCH_ASSOC);
                if (!$found) { echo '<div class="alert alert-err">Baris tidak ditemukan.</div>'; return; }
                foreach ($fields as $f) $rowVals[$f] = $found[$f] ?? null;
            } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
        }
        ?>
        <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><?= $isEdit ? 'Edit' : 'Tambah' ?> baris — <?= e($selTable) ?></h2>
          <span style="flex:1"></span><a class="btn btn-sm" href="<?= e($base) ?>">Batal</a></div>
          <form method="post" style="padding:16px">
            <input type="hidden" name="csrf" value="<?= e($t) ?>">
            <input type="hidden" name="act" value="<?= $isEdit ? 'update_row' : 'insert_row' ?>">
            <input type="hidden" name="db" value="<?= e($selDb) ?>">
            <input type="hidden" name="table" value="<?= e($selTable) ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="pk" value="<?= e($pkCol) ?>">
              <input type="hidden" name="pkv" value="<?= e($pkVal) ?>">
            <?php endif; ?>
            <div class="form-grid">
            <?php foreach ($cols as $c): ?>
              <?php if ($isEdit && $c['Field'] === $pkCol): ?>
                <div><label class="lbl"><?= e($c['Field']) ?> (PK, terkunci)</label>
                <input class="input mono" style="width:100%" value="<?= e((string)$pkVal) ?>" disabled></div>
              <?php else: ?>
                <?php row_field($c, $rowVals[$c['Field']] ?? null, $t); ?>
              <?php endif; ?>
            <?php endforeach; ?>
            </div>
            <div class="row" style="margin-top:12px"><button class="btn btn-primary" type="submit">Simpan</button></div>
          </form>
        </div>
        <?php
        return;
    }

    // Browse (default)
    try {
        $rows = $pdo->query("SELECT * FROM " . esc_ident($selTable) . " LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { echo '<div class="alert alert-err">' . e($e->getMessage()) . '</div>'; return; }
    ?>
    <div class="panel"><div class="panel-head"><h2><?= e($selTable) ?> — max 100 baris</h2>
      <span style="flex:1"></span>
      <a class="btn btn-sm btn-primary" href="<?= e($base) ?>&view=insert">+ Baris</a>
      <a class="btn btn-sm" href="<?= e($base) ?>&view=struct">Struktur</a></div>
      <div class="tscroll"><table><thead><tr>
        <?php foreach ($cols as $col): ?><th><?= e($col['Field']) ?></th><?php endforeach; ?>
        <th></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="20" class="muted">Tabel kosong. Klik + Baris.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
          <?php foreach ($cols as $col): $v = $r[$col['Field']] ?? null; ?>
            <td class="mono"><?= e(is_scalar($v) ? (string)$v : json_encode($v)) ?></td>
          <?php endforeach; ?>
          <td><div class="row">
            <a class="btn btn-sm" href="<?= e($base) ?>&view=edit&pk=<?= urlencode($pk) ?>&pkv=<?= urlencode((string)($r[$pk] ?? '')) ?>">Edit</a>
            <form method="post" onsubmit="return confirm('Hapus baris ini?')">
              <input type="hidden" name="csrf" value="<?= e($t) ?>"><input type="hidden" name="act" value="delete_row">
              <input type="hidden" name="db" value="<?= e($selDb) ?>">
              <input type="hidden" name="table" value="<?= e($selTable) ?>">
              <input type="hidden" name="col" value="<?= e($pk) ?>">
              <input type="hidden" name="val" value="<?= e((string)($r[$pk] ?? '')) ?>">
              <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
            </form>
          </div></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php
}

// Export ?page=databases&export=db ditangani di index.php sebelum render.
function databases_export(PDO $pdo, string $db): void {
    if (!valid_ident($db)) { http_response_code(400); exit('Nama DB tidak valid.'); }
    $pdo->exec("USE " . esc_ident($db));
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $db . '-' . date('Ymd-His') . '.sql"');
    echo "-- Export $db via dhexx panel " . date('c') . "\n\n";
    foreach ($tables as $tb) {
        $t = esc_ident($tb);
        $create = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_ASSOC);
        echo ($create['Create Table'] ?? '') . ";\n\n";
        $rows = $pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
            $cols = implode(',', array_map('esc_ident', array_keys($r)));
            echo "INSERT INTO $t ($cols) VALUES (" . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    exit;
}
