# Webserver Management dhexx

Panel hosting ringan berbahasa Indonesia — kelola **Files, Projects, Databases, System, Logs** dari browser.
PHP murni tanpa framework, SQLite untuk internal, MySQL untuk data user.

> Repo: `https://github.com/dhamm-dev/Webserver-Management-dhexx`
> Contoh live: `https://YOUR_DOMAIN/dashboard`

## Fitur

- **Files** — upload terstruktur per kategori `images / documents / videos / archives / audio / others`
  - Upload di root kategori = auto-pilah by MIME + ekstensi (`includes/helpers.php:32`)
  - Upload di dalam folder = tetap di folder itu. Dukung drag-drop multi-file, folder, rename/move/delete, search, preview (gambar/video/pdf/teks), download file + download folder sebagai `.zip`, extract `.zip` aman (`.php` & path traversal dilewati)
  - Batas 50 MB/file (`MAX_UPLOAD_BYTES` di `config.php:15`), tolak ekstensi `php,phtml,phar,htaccess,shtml,cgi,pl,py,sh,exe`
- **Projects** — katalog project + folder fisik di `storage/projects/nama-slug/`
  - Tipe `static/php/node/python/proxy`, status `active/paused/archived`, repo `https://…` + branch
  - Tombol `Status / Log / Pull / Clone` dieksekusi via worker root (aman, tanpa shell dari web)
  - Webhook GitHub: `POST webhook.php?token=SECRET&project=ID` → antre `git.pull` (token = `webhook_secret` di tabel settings)
- **Databases** — kelola MySQL langsung dari panel + tombol `Open phpMyAdmin`
  - Auto-connect dari `config.php` (`MYSQL_HOST/PORT/USER/PASS`) atau override manual per-sesi
  - Create/drop DB, create/drop/rename/truncate tabel (builder max 32 kolom), browse max 100 baris, insert/update/delete baris, import `.sql` (max 50 MB, max 2000 statement, tanpa procedure), export `.sql` per-DB, query read-only (`SELECT/SHOW/DESCRIBE/EXPLAIN` saja, tolak `;` + komentar + `INTO OUTFILE`)
- **System** — read-only + aksi terbatas via worker
  - Stat disk/mem/load/uptime/PHP, status `nginx/php-fpm/mysql` (pidfile → `/proc` → `pidof` → port/socket), grafik 24 jam, storage usage, tombol `Reload Nginx / Reload PHP-FPM`
- **Logs** — 200 aktivitas terakhir dari SQLite (`login, file_upload, deploy_pull, db_*`, dll) + tail log server `/www/wwwlogs/*.log` (300 baris, filter teks, read-only)
- **Auth** — login 2 langkah, rate-limit 5 gagal/5 menit per IP, session `httponly + SameSite=Lax`, CSRF token semua POST, password Argon2id, wajib ganti 12+ karakter setelah setup

## Syarat

- PHP 8.1+ dengan ekstensi `pdo_sqlite, pdo_mysql, zip, fileinfo, mbstring`
- Nginx / Apache + MySQL/MariaDB (opsional, hanya untuk modul Databases)
- Cron (untuk worker + kolektor metrik)
- Contoh path server: webroot `/www/wwwroot/YOUR_DOMAIN`, nginx `/www/server/nginx`, php-fpm 8.5

## Instal cepat (server baru)

```bash
# 1. Clone
git clone https://github.com/dhamm-dev/Webserver-Management-dhexx.git dashboard
cd dashboard

# 2. Config (JANGAN commit config.php asli)
cp config.example.php config.php
nano config.php
# isi: MYSQL_HOST (127.0.0.1), MYSQL_PORT (3306),
#      MYSQL_USER / MYSQL_PASS (user MySQL kamu),
#      PHPMYADMIN_URL (/phpmyadmin/), MAX_UPLOAD_BYTES

# 3. Permission (sesuaikan user web kamu: www / www-data)
chown -R www:www data storage
chmod 750 data storage

# 4. Nginx — taruh isi nginx-snippet.conf ke dalam server{} domain kamu:
#    - /dashboard/storage/ : matikan eksekusi .php
#    - /dashboard/data + setup.php : deny all
#    - client_max_body_size 50m;
nginx -t && nginx -s reload

# 5. Setup admin — buka SEKALI di browser:
#    https://DOMAIN_KAMU/dashboard/setup.php
#    isi username + password sementara -> login -> dipaksa ganti ke 12+ karakter

# 6. Wajib hapus setelah admin jadi:
rm setup.php
```

Lihat `DEPLOY.md` untuk contoh deploy lengkap.

## Cara pakai

### Files `?page=files`
1. Pilih tab kategori (`images/documents/...`), navigasi breadcrumb `~/storage/<cat>/sub/`.
2. `+ Baru` → `Folder baru` (nama `A-Z a-z 0-9 _ . -`, max 60) / `Upload file` (drag-drop, multi) / `Extract .zip`.
3. Di root kategori file otomatis dipilah (misal `.png` → `images/`). Di dalam folder file tetap di situ. Nama file disanitasi, duplikat ditambah `-His`.
4. Aksi per file: `Preview / Download / Rename / Move (antar folder se-kategori) / Hapus`. Per folder: `Zip (download) / Rename / Hapus rekursif`.
5. URL publik file = `dashboard/storage/<cat>/<sub>/<nama>` (relatif, ikut path deploy).

### Projects `?page=projects`
1. `Buat secret` sekali di panel `Webhook deploy` (tersimpan sebagai `webhook_secret`).
2. `Project baru` → isi Nama, Tipe, Repo `https://github.com/user/repo.git` (boleh kosong), Branch (`main`), Status, Notes → folder `storage/projects/<slug>/` dibuat otomatis + `README.txt`.
3. Jika ada repo: `Clone` (folder harus kosong) → `Status / Log` untuk cek → `Pull` untuk update. Output git tampil di atas tabel, hasil juga masuk `Logs`.
4. Auto-deploy GitHub: di repo → Settings → Webhooks → Add → URL `https://DOMAIN/dashboard/webhook.php?token=SECRET&project=ID` (lihat kolom Deploy), Content type `application/json`, event `Just the push event`, method wajib `POST`.
5. `Edit` ubah metadata, `Hapus` hanya hapus katalog (folder fisik dibiarkan).

### Databases `?page=databases`
1. Secara default pakai auto-connect dari `config.php`. Untuk DB lain: buka `Koneksi` → isi host/port/user/pass → `Pakai kredensial ini` (tersimpan di sesi). `Kembali ke auto` untuk reset. Ada link `Open phpMyAdmin`.
2. `Create DB` (nama `[A-Za-z0-9_]`), klik DB → daftar tabel → `+ Tabel` (builder tipe `INT/VARCHAR/TEXT/DATETIME/DECIMAL/JSON/...`, key `PRIMARY/UNIQUE/INDEX`, `AUTO_INCREMENT` otomatis jadi PRIMARY).
3. Klik tabel → `Browse` (100 baris), `+ Baris / Edit / Hapus`, `Struktur` (lihat `SHOW CREATE TABLE`), `Rename / Truncate / Drop` per tabel.
4. `Import .sql ke <db>` (hanya `.sql`), `Export .sql` per baris DB, `Query (read-only)` untuk `SELECT` cepat.
5. Semua DDL/DML penting dicatat di `Logs`.

### System `?page=system`
- Lihat disk/mem/load/PHP tanpa SSH. `Reload Nginx / PHP-FPM` antre ke worker (butuh worker root jalan, hasil tampil + masuk Logs).
- Agar `Grafik 24 jam` terisi, pasang cron kolektor (token otomatis dibuat di halaman itu):
```bash
*/5 * * * * curl -sf 'http://127.0.0.1/dashboard/index.php?collect=TOKEN_KAMU' >/dev/null 2>&1
```

### Logs `?page=logs`
- Tabel `activity` (time/user/action/detail, 200 terakhir). Untuk debug deploy/worker cari `deploy_pull / deploy_clone / svc_reload`.
- `Log server`: pilih file di `/www/wwwlogs/*.log` → `Tampil` (300 baris terakhir, bisa filter teks).

### Akun
- `password.php` — ganti password (min 12 karakter). Setelah setup, login pertama dipaksa ke sini (`?force`).
- `logout.php` — keluar + catat log.

## Worker (wajib untuk Pull/Clone/Reload)

Web PHP tidak boleh `shell_exec` git/service. Web hanya tulis `data/queue/<id>.job`, eksekusi oleh `dhexx-worker.php` sebagai root:

```bash
# test manual:
php dhexx-worker.php /www/wwwroot/YOUR_DOMAIN/dashboard

# produksi — cron root tiap menit:
* * * * * /usr/bin/php /www/wwwroot/YOUR_DOMAIN/dashboard/dhexx-worker.php /www/wwwroot/YOUR_DOMAIN/dashboard >>/var/log/dhexx-worker.log 2>&1
```

Worker hanya mengizinkan: `git.status/log/pull/clone` (repo `https://…`, branch `[A-Za-z0-9/_.-]`, dir wajib di `/www/wwwroot/` atau `storage/projects/`), `svc.nginx-reload` (`nginx -t` dulu), `svc.php-reload` (cek `/proc/<pid>` = php-fpm). Timeout job 10 menit, hasil `.result.json` dibaca web max 25 detik lalu dihapus 1 jam kemudian.

## Struktur

```
index.php login.php logout.php password.php setup.php webhook.php
dhexx-worker.php nginx-snippet.conf DEPLOY.md
config.php (PRIVAT, di-ignore) | config.example.php (template publik)
includes/ db.php auth.php helpers.php jobs.php mysql_admin.php metrics.php layout.php
modules/ files.php projects.php databases.php system.php logs.php
assets/ style.css app.js favicon.svg
data/ .htaccess (Deny from all) + panel.sqlite + queue/ + worker.lock (di-ignore)
storage/ index.html + images/ documents/ videos/ archives/ audio/ others/ projects/ (isi di-ignore)
```

## Keamanan

- `config.php` masuk `.gitignore` — jangan pernah push (berisi `MYSQL_PASS`). Publik hanya `config.example.php`.
- `data/*.sqlite*`, `data/queue/*.job|*.result.json`, `data/worker.lock` di-ignore. `data/.htaccess` tetap ikut.
- Isi `storage/*` di-ignore (kecuali `index.html` + `.gitkeep` struktur). Blok eksekusi PHP di `storage/` + blok akses `data/` via `nginx-snippet.conf`.
- Validasi ketat: nama file/folder, subpath (tolak `..`), identifier DB (`[A-Za-z0-9_]`), repo/branch, query read-only, CSRF semua POST, escape identifier dengan backtick.

## Troubleshooting

- `Gagal terhubung (auto maupun manual)` → cek `config.php` MYSQL_* + user MySQL boleh remote/localhost, atau isi override di panel Databases.
- `Worker belum selesai (>25 dtk)` → cek cron root jalan, `data/queue/` writable oleh `www`, `data/worker.lock` tidak macet, lalu lihat `Logs`.
- `Upload gagal / Terlalu besar` → samakan `MAX_UPLOAD_BYTES` (50 MB) dengan `client_max_body_size 50m` + `upload_max_filesize/post_max_size` PHP.
- `Setup sudah selesai. Hapus file setup.php` → normal, hapus `setup.php` setelah admin jadi.
- `Terlalu banyak gagal. Coba lagi dalam 5 menit` → rate-limit login, tunggu atau cek tabel `login_attempts` di SQLite.
- Grafik kosong → kolektor `?collect=` belum dipasang di cron.
- `.zip` gagal extract → butuh ekstensi PHP `zip`, max 1000 file / 200 MB per zip, `.php` otomatis dilewati.

## Lisensi

Bebas pakai untuk kebutuhan sendiri/komersial. Tanpa garansi. Jangan commit `config.php`, `data/*`, dan isi `storage/*` ke publik.
