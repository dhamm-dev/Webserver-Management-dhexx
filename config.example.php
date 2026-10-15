<?php
// dhexx panel — config EXAMPLE. Copy ke config.php lalu isi kredensial asli.
//   cp config.example.php config.php
declare(strict_types=1);

define('APP_NAME', 'dhexx / panel');
define('APP_ROOT', __DIR__);
define('STORAGE_ROOT', APP_ROOT . '/storage');
define('DATA_DIR', APP_ROOT . '/data');
define('SQLITE_PATH', DATA_DIR . '/panel.sqlite');

// Kategori upload -> subfolder storage/
const FILE_CATS = ['images','documents','videos','archives','audio','others'];

// Batas upload (samakan dengan Nginx client_max_body_size)
define('MAX_UPLOAD_BYTES', 50 * 1024 * 1024);

// phpMyAdmin lewat path yang sama (lolos Cloudflare, tidak perlu port :887)
define('PHPMYADMIN_URL', '/phpmyadmin/');

// Kredensial MySQL bawaan untuk auto-connect halaman Databases.
// Dipakai hanya bila tidak ada override manual di sesi.
// JANGAN commit config.php asli — file ini hanya contoh.
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', 3306);
define('MYSQL_USER', 'root');
define('MYSQL_PASS', 'GANTI-DENGAN-PASSWORD-ASLI');

// Rate limit login
define('LOGIN_MAX_FAIL', 5);
define('LOGIN_BLOCK_SECONDS', 300);

// Ekstensi yang ditolak keras di storage/ (anti webshell)
const BLOCKED_EXT = ['php','phtml','phar','htaccess','shtml','cgi','pl','py','sh','exe'];
