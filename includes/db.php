<?php
// SQLite lokal untuk user, projects, logs. Tanpa dependensi.
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $pdo = new PDO('sqlite:' . SQLITE_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        pass_hash TEXT NOT NULL,
        must_change INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts(
        ip TEXT PRIMARY KEY,
        fails INTEGER NOT NULL DEFAULT 0,
        last_at INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'static',
        path TEXT NOT NULL,
        repo TEXT NOT NULL DEFAULT '',
        branch TEXT NOT NULL DEFAULT 'main',
        status TEXT NOT NULL DEFAULT 'active',
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    )");
    // migrasi: tambah kolom branch bila DB lama
    try {
        $cols = array_column($pdo->query("PRAGMA table_info(projects)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('branch', $cols, true)) $pdo->exec("ALTER TABLE projects ADD COLUMN branch TEXT NOT NULL DEFAULT 'main'");
    } catch (Throwable $e) { /* abaikan */ }
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        action TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(
        k TEXT PRIMARY KEY,
        v TEXT NOT NULL DEFAULT ''
    )");
    return $pdo;
}

function log_activity(string $user, string $action, string $detail = ''): void {
    db()->prepare("INSERT INTO activity(username,action,detail,created_at) VALUES(?,?,?,datetime('now','localtime'))")
        ->execute([$user, $action, $detail]);
}

function setting_get(string $k, string $def = ''): string {
    try {
        $v = db()->prepare("SELECT v FROM settings WHERE k=?");
        $v->execute([$k]);
        $r = $v->fetchColumn();
        return $r === false ? $def : (string)$r;
    } catch (Throwable $e) {
        return $def;
    }
}

function setting_set(string $k, string $v): void {
    db()->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]);
}
