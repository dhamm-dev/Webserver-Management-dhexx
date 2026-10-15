# Deploy panel ke YOUR_DOMAIN/dashboard
# Jalankan perintah di bawah ini di terminal server (butuh password sudo).

# 1) Backup dulu (wajib)
sudo tar -czf /root/wwwroot-backup-$(date +%F).tar.gz /www/wwwroot/YOUR_DOMAIN

# 2) Copy dashboard
sudo cp -r ./dashboard /www/wwwroot/YOUR_DOMAIN/dashboard
sudo chown -R www:www /www/wwwroot/YOUR_DOMAIN/dashboard
sudo chmod 750 /www/wwwroot/YOUR_DOMAIN/dashboard/data
sudo chmod 750 /www/wwwroot/YOUR_DOMAIN/dashboard/storage

# 3) Cari vhost domain kamu, lalu tambahkan isi nginx-snippet.conf ke dalam blok server{}
ls /www/server/panel/vhost/nginx/
sudo nano /www/server/panel/vhost/nginx/YOUR_DOMAIN.conf
# (ganti alias path bila webroot berbeda; pastikan client_max_body_size 50m)

# 4) Test + reload nginx
sudo /www/server/nginx/sbin/nginx -t -c /www/server/nginx/conf/nginx.conf
sudo /www/server/nginx/sbin/nginx -s reload -c /www/server/nginx/conf/nginx.conf

# 5) Setup admin (buka di browser, sekali saja)
#    https://YOUR_DOMAIN/dashboard/setup.php -> buat username + password sementara
#    Login -> langsung dipaksa ganti password 12+ karakter.

# 6) Hapus setup.php setelah admin jadi
sudo rm /www/wwwroot/YOUR_DOMAIN/dashboard/setup.php

# 7) Verifikasi
#    - https://YOUR_DOMAIN/dashboard/ -> Files/Projects/Databases/System/Logs
#    - Upload .png masuk ke images/, upload .php DITOLAK
#    - Databases -> Connect (host 127.0.0.1, user + password MySQL kamu) -> kelola DB
