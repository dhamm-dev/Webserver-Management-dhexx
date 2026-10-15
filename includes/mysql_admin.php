<?php
// Koneksi MySQL admin. Kredensial bawaan dari config.php (auto-connect);
// override manual di session menang bila ada.
declare(strict_types=1);

function mysql_conn(): ?PDO {
    start_session();
    $c = $_SESSION['mysql'] ?? [
        'host' => MYSQL_HOST,
        'port' => MYSQL_PORT,
        'user' => MYSQL_USER,
        'pass' => MYSQL_PASS,
        'auto' => true,
    ];
    try {
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_EMULATE_PREPARES => false, // cegah multi-statement via emulasi
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        $_SESSION['mysql_error'] = $e->getMessage();
        return null;
    }
}

/** Validasi nama identifier DB/tabel (anti injection karena tidak bisa di-bind). */
function valid_ident(string $s): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $s);
}

/** Escape identifier (nama DB/tabel/kolom dari metadata) dengan backtick ganda. */
function esc_ident(string $s): string {
    return '`' . str_replace('`', '``', $s) . '`';
}

/**
 * Validator query bebas: hanya SATU statement read-only.
 * Menolak: stacked query (;), komentar SQL, dan SELECT ... INTO/OUTFILE
 * (tulis file di server) / LOAD_FILE / LOAD DATA.
 * String literal '...' / "..." dikupas dulu agar tidak false-positive.
 */
function validate_read_query(string $q): bool {
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $q)) return false;
    $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $q);
    $s = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', (string)$s);
    if (str_contains($s, ';')) return false;
    if (preg_match('/--|\\/\\*|#/', $s)) return false;
    if (preg_match('/\\b(INTO|OUTFILE|DUMPFILE|LOAD_FILE|LOAD\\s+DATA|PROCEDURE)\\b/i', $s)) return false;
    return true;
}
