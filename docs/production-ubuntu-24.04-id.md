# Checklist Production ISP Multi-Kota dan Instalasi Ubuntu 24.04

> Catatan: rilis LTS Ubuntu yang tersedia adalah **Ubuntu 24.04 LTS**. Jika yang dimaksud `24.05`, gunakan 24.04 LTS untuk stabilitas produksi.

## Status kesiapan produksi

MyShezaNet sudah memakai stack modern Laravel 12, PHP 8.2+, MySQL/MariaDB, Redis/queue, scheduler, dan integrasi ISP seperti Mikrotik, RADIUS, SNMP, payment gateway, WhatsApp, serta GenieACS. Untuk operasional ISP multi-kota, aplikasi harus dipasang dengan arsitektur high-availability, segmentasi jaringan, backup teruji, observability, dan konfigurasi integrasi yang dibatasi per service.

## Arsitektur rekomendasi multi-kota

```text
Admin VPN / Bastion
        |
 WAF / Load Balancer / Reverse Proxy TLS
        |
+-------------------+      +-------------------+
| App node kota A   |      | App node kota B   |
| Nginx + PHP-FPM   |      | Nginx + PHP-FPM   |
+-------------------+      +-------------------+
        |                          |
        +------------+-------------+
                     |
             MySQL primary/cluster
                     |
       Replica read/reporting + backup PITR

Integrasi internal:
- Mikrotik/BRAS/BNG API hanya dari subnet aplikasi atau VPN manajemen.
- GenieACS NBI hanya dari subnet aplikasi; CWMP publik dibatasi ke CPE/edge.
- RADIUS database/service dipisah dan diberi kredensial least privilege.
- Redis dipakai untuk cache, session, queue, dan rate limit.
```

## Baseline hardening wajib

1. Gunakan `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://domain-resmi`.
2. Jangan memakai kredensial default; rotasi admin awal setelah instalasi.
3. Pakai HTTPS, HSTS, firewall, fail2ban, dan akses admin melalui VPN atau allowlist IP.
4. Pisahkan database produksi dari reporting berat; gunakan replica/warehouse untuk laporan besar.
5. Jalankan Laravel scheduler setiap menit dan queue worker sebagai service systemd.
6. Aktifkan backup harian plus point-in-time recovery, lalu uji restore minimal bulanan.
7. Setiap integrasi eksternal wajib memiliki timeout, retry terbatas, audit log, dan secret unik.
8. Jangan expose port MySQL, Redis, GenieACS NBI, Mikrotik API, atau SNMP ke internet publik.

## Konfigurasi aman GenieACS

Aplikasi mendukung konfigurasi koneksi GenieACS melalui environment variable berikut:

```env
GENIEACS_URL=http://127.0.0.1:7557
GENIEACS_USERNAME=isi_user_nbi
GENIEACS_PASSWORD=isi_password_kuat
GENIEACS_TIMEOUT=10
GENIEACS_RETRY_TIMES=2
GENIEACS_RETRY_SLEEP=250
```

Rekomendasi produksi:

- Tempatkan GenieACS NBI di jaringan privat atau di belakang reverse proxy internal dengan TLS.
- Gunakan basic auth atau auth layer reverse proxy untuk NBI.
- Batasi akses NBI hanya dari IP aplikasi MyShezaNet.
- Pisahkan port CWMP `7547`, NBI `7557`, FS `7567`, dan UI sesuai kebutuhan firewall.
- Jangan menyimpan password CPE, token, atau payload sensitif di log aplikasi.

## Langkah instalasi di Ubuntu 24.04 LTS

### 1. Siapkan server

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Jakarta
sudo apt install -y curl git unzip ca-certificates software-properties-common ufw fail2ban
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
```

### 2. Install PHP, Nginx, MySQL, Redis, Composer, dan Node.js

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y nginx mysql-server redis-server \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
  php8.2-redis php8.2-snmp php8.2-sockets
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 3. Buat database dan user least privilege

```bash
sudo mysql
```

```sql
CREATE DATABASE gembok_lara CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gembok_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_PANJANG_UNIK';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON gembok_lara.* TO 'gembok_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Setelah migrasi pertama stabil, pertimbangkan memisahkan user migrasi dan user runtime dengan privilege lebih kecil.

### 4. Deploy kode aplikasi

```bash
sudo mkdir -p /var/www
sudo git clone https://github.com/rizkylab/gembok-lara.git /var/www/gembok-lara
cd /var/www/gembok-lara
sudo composer install --no-dev --prefer-dist --optimize-autoloader
sudo npm ci
sudo npm run build
sudo cp .env.example .env
sudo php artisan key:generate --force
```

### 5. Isi `.env` produksi

```env
APP_NAME="MyShezaNet"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billing.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gembok_lara
DB_USERNAME=gembok_app
DB_PASSWORD=GANTI_PASSWORD_PANJANG_UNIK

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

GENIEACS_URL=http://127.0.0.1:7557
GENIEACS_USERNAME=isi_user_nbi
GENIEACS_PASSWORD=isi_password_kuat
GENIEACS_TIMEOUT=10
GENIEACS_RETRY_TIMES=2
GENIEACS_RETRY_SLEEP=250
```

### 6. Migrasi, optimasi, dan permission

```bash
sudo php artisan migrate --force
sudo php artisan storage:link
sudo php artisan config:cache
sudo php artisan route:cache
sudo php artisan view:cache
sudo chown -R www-data:www-data /var/www/gembok-lara
sudo find /var/www/gembok-lara -type f -exec chmod 0644 {} \;
sudo find /var/www/gembok-lara -type d -exec chmod 0755 {} \;
sudo chmod -R ug+rwx /var/www/gembok-lara/storage /var/www/gembok-lara/bootstrap/cache
```

### 7. Konfigurasi Nginx

```bash
sudo cp /var/www/gembok-lara/scripts/nginx-gembok.conf /etc/nginx/sites-available/gembok-lara
sudo sed -i 's|/var/www/gembok-lara|/var/www/gembok-lara|g' /etc/nginx/sites-available/gembok-lara
sudo ln -sfn /etc/nginx/sites-available/gembok-lara /etc/nginx/sites-enabled/gembok-lara
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Pasang TLS:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d billing.example.com
```

### 8. Pasang scheduler dan queue worker

Cron scheduler:

```bash
( sudo crontab -u www-data -l 2>/dev/null; echo '* * * * * cd /var/www/gembok-lara && php artisan schedule:run >> /dev/null 2>&1' ) | sudo crontab -u www-data -
```

Systemd queue worker:

```bash
sudo tee /etc/systemd/system/gembok-lara-queue.service >/dev/null <<'SERVICE'
[Unit]
Description=MyShezaNet Laravel Queue Worker
After=network.target mysql.service redis-server.service

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/gembok-lara/artisan queue:work redis --sleep=3 --tries=3 --timeout=120
WorkingDirectory=/var/www/gembok-lara

[Install]
WantedBy=multi-user.target
SERVICE
sudo systemctl daemon-reload
sudo systemctl enable --now gembok-lara-queue
```

### 9. Smoke test setelah instalasi

```bash
curl -fsS https://billing.example.com/api/health
php artisan about
php artisan schedule:list
systemctl status gembok-lara-queue --no-pager
```

### 10. Operasional rilis aman

Untuk update berikutnya:

```bash
cd /var/www/gembok-lara
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan down --render="errors::503"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
sudo systemctl restart php8.2-fpm gembok-lara-queue
```
