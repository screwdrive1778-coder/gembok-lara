# Panduan Instalasi Production Grade Kill Bill untuk ISP Billing Management

Dokumen ini adalah checklist operasional untuk menjalankan Kill Bill sebagai sistem billing ISP yang stabil, aman, dan siap produksi. Fokusnya adalah arsitektur deployment, hardening aplikasi, database, proteksi injeksi SQL, observability, backup, dan proses rilis.

## 1. Ruang lingkup dan asumsi

Panduan ini ditujukan untuk deployment Kill Bill API sebagai billing engine ISP. Komponen portal pelanggan, RADIUS/BRAS/BNG, provisioning jaringan, payment gateway, notifikasi WhatsApp/SMS/email, dan OSS/BSS eksternal sebaiknya diintegrasikan melalui API atau plugin, bukan dimasukkan langsung ke database Kill Bill.

Asumsi minimum produksi:

- Minimal dua node aplikasi Kill Bill di belakang load balancer.
- Database MySQL atau MariaDB managed/clustered dengan replikasi dan backup point-in-time recovery.
- HTTPS terminasi di load balancer atau reverse proxy.
- Secret tidak disimpan di repository Git.
- Akses admin hanya melalui VPN, bastion host, atau jaringan internal yang tersegmentasi.

## 2. Arsitektur referensi ISP

```text
Internet / Admin VPN
        |
   WAF / Load Balancer / Reverse Proxy
        |
+-------------------+        +-------------------+
| Kill Bill node A  |        | Kill Bill node B  |
+-------------------+        +-------------------+
        |                            |
        +------------+---------------+
                     |
          MySQL / MariaDB primary
                     |
        Replica + backup + PITR storage

Integrasi eksternal:
- CRM / customer portal -> Kill Bill REST API
- RADIUS/BRAS/BNG provisioning -> plugin atau service orkestrasi
- Payment gateway / bank VA / e-wallet -> plugin pembayaran
- Email/SMS/WhatsApp -> notification service
- Data warehouse/reporting -> read replica atau event bus, bukan query langsung ke primary produksi
```

Prinsip penting untuk ISP:

1. Kill Bill menjadi sumber kebenaran untuk akun, subscription, invoice, dan pembayaran.
2. Sistem provisioning jaringan membaca status entitlement/subscription dari API atau event, lalu mengaktifkan, menangguhkan, atau memutus layanan pelanggan.
3. Integrasi eksternal harus idempotent agar retry tidak menyebabkan tagihan, pembayaran, atau provisioning ganda.
4. Hindari akses tulis langsung ke tabel Kill Bill dari aplikasi lain.

## 3. Prasyarat produksi

### Infrastruktur

- Linux server/container image yang dipatch rutin.
- Java runtime sesuai versi yang didukung oleh rilis Kill Bill yang digunakan.
- Database MySQL/MariaDB dengan parameter produksi, storage terenkripsi, dan backup otomatis.
- NTP/chrony aktif pada semua node untuk menjaga konsistensi waktu billing.
- DNS internal untuk database dan service dependency.
- Load balancer dengan health check aktif.

### Kapasitas awal yang disarankan

Ukuran aktual harus diuji dengan data dan pola invoice ISP Anda, tetapi baseline awal yang aman:

- Kill Bill API: 2 node, masing-masing 4 vCPU dan 8-16 GB RAM.
- Database: 4-8 vCPU, RAM 16-32 GB, SSD/NVMe, IOPS terukur.
- Connection pool: mulai konservatif, lalu naikkan berdasarkan metrik latency dan kapasitas database.
- Pisahkan workload reporting berat ke read replica atau warehouse.

## 4. Instalasi high level

1. Pilih versi rilis Kill Bill yang stabil dan pin versi image/artifact. Jangan memakai tag mengambang seperti `latest` untuk produksi.
2. Siapkan database kosong khusus Kill Bill dengan user aplikasi berhak minimum.
3. Jalankan migrasi schema sesuai mekanisme deployment Kill Bill yang dipilih.
4. Deploy minimal dua node Kill Bill dengan konfigurasi identik kecuali identitas instance bila diperlukan.
5. Pasang plugin yang diperlukan, misalnya payment gateway, catalog/plugin ISP, dan notifikasi.
6. Konfigurasikan catalog produk ISP: paket internet, siklus billing, pajak, diskon, grace period, dan aturan suspend/terminate.
7. Pasang reverse proxy/load balancer dengan TLS, rate limit, dan header keamanan.
8. Jalankan smoke test API, test invoice, test pembayaran, test refund, test suspend/resume, dan test provisioning.
9. Aktifkan monitoring, alert, centralized log, backup, dan audit trail sebelum menerima traffic produksi.

## 5. Konfigurasi database aman

### User dan hak akses

Gunakan user database khusus aplikasi. Jangan gunakan `root` atau akun administrator database untuk runtime aplikasi.

Contoh prinsip privilege:

```sql
CREATE USER 'killbill_app'@'10.%' IDENTIFIED BY 'gunakan-secret-manager';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON killbill.* TO 'killbill_app'@'10.%';
```

Sesuaikan privilege dengan kebutuhan migrasi. Jika proses migrasi membutuhkan `ALTER`, gunakan user migrasi terpisah yang hanya dipakai saat deployment, bukan user runtime aplikasi.

### Hardening database

- Aktifkan TLS antara aplikasi dan database bila traffic melewati jaringan yang tidak sepenuhnya privat.
- Batasi inbound database hanya dari subnet aplikasi.
- Aktifkan audit log untuk perubahan privilege dan akses administratif.
- Aktifkan slow query log dengan threshold yang sesuai.
- Pastikan collation dan timezone konsisten di semua node.
- Aktifkan backup harian dan point-in-time recovery.
- Uji restore secara berkala, bukan hanya mengecek bahwa backup berhasil dibuat.

## 6. Proteksi SQL injection dan data tampering

Kill Bill menggunakan lapisan DAO dan query yang terstruktur. Risiko SQL injection biasanya muncul dari custom plugin, integrasi eksternal, script operasional, atau dashboard/reporting yang dibuat khusus. Terapkan aturan berikut:

### Aturan wajib untuk plugin dan integrasi

- Selalu gunakan parameterized query atau prepared statement.
- Jangan menggabungkan input user ke string SQL.
- Validasi dan normalisasi semua input dari portal, CRM, payment callback, dan provisioning service.
- Gunakan allowlist untuk field yang boleh dipakai sebagai filter, sort, atau nama kolom.
- Jangan menerima nama tabel, nama kolom, atau potongan `WHERE` mentah dari request API.
- Gunakan ORM/query builder dengan binding parameter bila tersedia.
- Terapkan limit/pagination maksimum untuk endpoint pencarian pelanggan dan invoice.
- Log request ID dan tenant/account ID, tetapi jangan log token, password, nomor kartu penuh, atau secret pembayaran.

Contoh pola yang aman:

```java
// Aman: parameter dibind, bukan digabungkan ke SQL mentah
handle.createQuery("select * from isp_customer_mapping where account_id = :accountId")
      .bind("accountId", accountId)
      .mapToBean(CustomerMapping.class)
      .list();
```

Contoh pola yang dilarang:

```java
// Tidak aman: input eksternal digabungkan langsung ke SQL
String sql = "select * from isp_customer_mapping where account_id = '" + accountId + "'";
```

### Kontrol tambahan

- Jalankan static analysis untuk custom plugin sebelum rilis.
- Tambahkan dependency scanning untuk library plugin.
- Review semua query dinamis secara manual.
- Gunakan Web Application Firewall sebagai lapisan tambahan, tetapi jangan mengandalkan WAF sebagai satu-satunya proteksi.
- Terapkan least privilege di database agar eksploitasi injeksi tidak otomatis menjadi kompromi total.

## 7. Hardening API dan akses aplikasi

- Semua akses API produksi wajib melalui HTTPS.
- Nonaktifkan akses publik ke endpoint admin internal bila ada; batasi dengan network policy.
- Terapkan autentikasi dan otorisasi yang kuat untuk user admin, portal, dan service-to-service.
- Gunakan credential unik per integrasi, bukan satu shared admin credential.
- Rotasi API key dan secret secara berkala.
- Terapkan rate limiting per tenant, per IP, dan per credential.
- Gunakan timeout ketat untuk koneksi outbound ke payment gateway dan service provisioning.
- Aktifkan request body size limit untuk mencegah abuse.
- Pastikan CORS hanya mengizinkan domain portal resmi.
- Jangan mengekspos stack trace atau pesan error database ke pelanggan.

## 8. Secret management

- Simpan password database, API key payment gateway, signing secret webhook, dan credential SMTP/SMS di secret manager.
- Inject secret melalui environment variable, mounted secret file, atau mekanisme secret platform; jangan commit ke Git.
- Enkripsi secret at rest.
- Batasi akses secret berdasarkan service account.
- Rotasi secret setelah incident, pergantian vendor, atau perubahan anggota tim yang memiliki akses.

## 9. Catalog dan aturan billing ISP

Modelkan produk ISP dengan jelas:

- Paket recurring bulanan, prabayar, pascabayar, atau hybrid.
- Add-on seperti IP publik, static IP, speed boost, modem rental, dan managed router.
- Grace period untuk invoice terlambat.
- Dunning rule untuk reminder, suspend, reconnect, dan terminate.
- Pajak dan biaya administrasi sesuai wilayah operasi.
- Kebijakan prorata untuk upgrade/downgrade di tengah siklus.

Sebelum produksi, lakukan test skenario:

1. Pelanggan baru aktif di tengah bulan.
2. Upgrade paket sebelum invoice berikutnya.
3. Downgrade paket dengan prorata.
4. Payment success, pending, failed, expired, chargeback, dan refund.
5. Invoice terlambat melewati grace period.
6. Suspend dan resume layanan setelah pembayaran.
7. Termination dan reactivation.
8. Migrasi pelanggan existing dari sistem lama.

## 10. Observability dan alerting

Aktifkan minimal:

- Health check aplikasi per node.
- Metrik JVM: heap, GC pause, thread, file descriptor.
- Metrik HTTP: latency p50/p95/p99, error rate, throughput.
- Metrik database: active connection, slow query, lock wait, replication lag, disk usage.
- Metrik bisnis: invoice generated, invoice failed, payment success/failure, overdue account, suspended account.
- Centralized log dengan correlation ID.
- Alert untuk error payment callback, backlog invoice, gagal provisioning, dan backup gagal.

Threshold awal yang umum:

- HTTP 5xx rate melebihi 1% selama 5 menit.
- Database replication lag melebihi 60 detik.
- Disk database di atas 80%.
- JVM heap usage konsisten di atas 85%.
- Payment callback failure spike dibanding baseline.

## 11. Backup, restore, dan disaster recovery

- Backup database minimal harian dengan PITR.
- Simpan backup di lokasi berbeda dan terenkripsi.
- Retensi backup disesuaikan dengan regulasi dan kebutuhan audit.
- Uji restore ke environment staging minimal bulanan.
- Dokumentasikan RPO dan RTO.
- Pastikan file konfigurasi, catalog, plugin, dan versi artifact dapat direkonstruksi dari Git atau artifact repository.
- Siapkan runbook failover database dan rollback aplikasi.

## 12. Pipeline rilis yang aman

Setiap perubahan catalog, plugin, schema, atau konfigurasi harus melewati:

1. Code review.
2. Unit test.
3. Integration test dengan database.
4. Security scan dependency.
5. Static analysis untuk query dan input handling.
6. Staging test dengan data sintetis mirip produksi.
7. Backup sebelum deploy.
8. Canary atau rolling deployment.
9. Verifikasi smoke test setelah deploy.
10. Rencana rollback tertulis.

## 13. Checklist go-live

Gunakan checklist ini sebelum membuka traffic produksi:

- [ ] Semua endpoint publik memakai HTTPS valid.
- [ ] Database tidak bisa diakses dari internet.
- [ ] User database runtime bukan admin/root.
- [ ] Secret tidak ada di repository, image, atau log.
- [ ] Backup dan restore sudah diuji.
- [ ] Monitoring dan alert sudah aktif.
- [ ] Catalog billing sudah diuji untuk seluruh paket ISP.
- [ ] Payment callback diverifikasi dengan signature atau mekanisme autentikasi vendor.
- [ ] Provisioning suspend/resume idempotent.
- [ ] Rate limit dan body size limit aktif.
- [ ] Log tidak membocorkan data sensitif.
- [ ] Runbook incident, rollback, dan DR tersedia.
- [ ] Tim operasional sudah menjalankan simulasi incident.


## 14. Instalasi step-by-step di Ubuntu Server 24.04 LTS

> Catatan: Ubuntu tidak merilis versi LTS bernama 24.05. Jika server Anda tertulis 24.05, verifikasi dulu dengan `lsb_release -a`. Panduan ini diasumsikan untuk Ubuntu Server 24.04 LTS karena itulah rilis produksi Ubuntu 24.x yang umum digunakan.

Bagian ini memakai pendekatan production-grade berbasis paket OS, systemd, Nginx reverse proxy, Java runtime, dan MySQL/MariaDB. Untuk cluster serius, database managed atau cluster eksternal tetap lebih disarankan daripada database tunggal di VM yang sama.

### 14.1. Variabel yang harus Anda tetapkan

Ganti nilai berikut sesuai environment Anda sebelum menjalankan perintah:

```bash
export KILLBILL_VERSION="PIN_VERSI_RILIS_KILLBILL"
export KILLBILL_HOSTNAME="billing.example.net"
export DB_HOST="127.0.0.1"
export DB_NAME="killbill"
export DB_APP_USER="killbill_app"
export DB_MIGRATION_USER="killbill_migration"
export KB_RUNTIME_USER="killbill"
```

Jangan menaruh password di shell history. Buat password dengan secret manager atau minimal `openssl rand -base64 48`, lalu masukkan melalui prompt interaktif atau file environment yang permission-nya dikunci.

### 14.2. Update OS dan paket dasar

```bash
sudo apt update
sudo apt -y full-upgrade
sudo apt -y install \
  ca-certificates curl wget gnupg lsb-release unzip jq vim less \
  chrony openssl ufw fail2ban acl logrotate \
  openjdk-17-jre-headless nginx mariadb-server mariadb-client
sudo reboot
```

Setelah reboot:

```bash
lsb_release -a
java -version
systemctl status chrony --no-pager
```

### 14.3. Hardening user, direktori, dan permission

```bash
sudo useradd --system --home /var/lib/killbill --shell /usr/sbin/nologin killbill
sudo install -d -o killbill -g killbill -m 0750 /var/lib/killbill
sudo install -d -o killbill -g killbill -m 0750 /var/log/killbill
sudo install -d -o killbill -g killbill -m 0750 /opt/killbill
sudo install -d -o root -g killbill -m 0750 /etc/killbill
```

Buat file secret runtime:

```bash
sudo install -o root -g killbill -m 0640 /dev/null /etc/killbill/killbill.env
sudoedit /etc/killbill/killbill.env
```

Isi minimal:

```bash
KILLBILL_DAO_URL=jdbc:mysql://127.0.0.1:3306/killbill?useSSL=true&serverTimezone=UTC&allowPublicKeyRetrieval=false
KILLBILL_DAO_USER=killbill_app
KILLBILL_DAO_PASSWORD=GANTI_DENGAN_SECRET_DB_APP
KILLBILL_SERVER_TEST_MODE=false
JAVA_OPTS=-Xms4g -Xmx4g -XX:+UseG1GC -Dfile.encoding=UTF-8 -Duser.timezone=UTC
```

### 14.4. Konfigurasi firewall host

Jika server berada di belakang load balancer, batasi port 80/443 hanya dari load balancer. Contoh baseline:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose
```

Untuk produksi, ganti rule SSH menjadi hanya dari IP VPN/bastion:

```bash
sudo ufw delete allow OpenSSH
sudo ufw allow from IP_VPN_ATAU_BASTION to any port 22 proto tcp
```

### 14.5. Hardening MariaDB/MySQL

Jalankan hardening awal:

```bash
sudo mysql_secure_installation
```

Buat database, user migrasi, dan user runtime. Gunakan password kuat yang berbeda untuk masing-masing user.

```bash
sudo mysql
```

```sql
CREATE DATABASE killbill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'killbill_migration'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_MIGRASI';
CREATE USER 'killbill_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_RUNTIME';
GRANT ALL PRIVILEGES ON killbill.* TO 'killbill_migration'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON killbill.* TO 'killbill_app'@'localhost';
FLUSH PRIVILEGES;
```

Tambahkan konfigurasi database produksi:

```bash
sudo tee /etc/mysql/mariadb.conf.d/60-killbill-production.cnf >/dev/null <<'EOF_DB'
[mysqld]
bind-address=127.0.0.1
max_connections=300
innodb_buffer_pool_size=8G
innodb_flush_log_at_trx_commit=1
innodb_file_per_table=1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-time-zone='+00:00'
slow_query_log=ON
long_query_time=1
log_queries_not_using_indexes=OFF
EOF_DB
sudo systemctl restart mariadb
sudo systemctl status mariadb --no-pager
```

Sesuaikan `innodb_buffer_pool_size` sekitar 50-70% RAM server database bila database berada di host khusus. Jangan memakai angka `8G` begitu saja pada server kecil.

### 14.6. Instal artifact Kill Bill dengan versi yang dipin

Gunakan artifact resmi atau artifact internal yang sudah Anda build dan scan. Jangan gunakan URL contoh tanpa memverifikasi checksum dan provenance.

Contoh alur aman:

```bash
sudo install -d -o root -g root -m 0755 /opt/killbill/releases
cd /tmp
# Unduh artifact versi yang sudah dipin dari repository resmi/internal Anda.
# wget https://ARTIFACT_REPOSITORY/killbill-${KILLBILL_VERSION}.war
# wget https://ARTIFACT_REPOSITORY/killbill-${KILLBILL_VERSION}.war.sha256
# sha256sum -c killbill-${KILLBILL_VERSION}.war.sha256
sudo install -o root -g root -m 0644 killbill-${KILLBILL_VERSION}.war /opt/killbill/releases/killbill-${KILLBILL_VERSION}.war
sudo ln -sfn /opt/killbill/releases/killbill-${KILLBILL_VERSION}.war /opt/killbill/killbill.war
```

Jika artifact belum tersedia, build di CI/CD atau build host terpisah, bukan di server produksi. Server produksi sebaiknya hanya menerima artifact final yang sudah ditandatangani/diverifikasi.

### 14.7. Service systemd Kill Bill

Buat unit systemd. Sesuaikan command `ExecStart` dengan packaging Kill Bill yang Anda gunakan; contoh berikut mengasumsikan artifact runnable atau launcher internal Anda dapat menjalankan WAR secara langsung. Jika deployment Anda memakai Tomcat, pasang WAR ke direktori Tomcat dan letakkan hardening JVM/environment di unit Tomcat.

```bash
sudo tee /etc/systemd/system/killbill.service >/dev/null <<'EOF_SYSTEMD'
[Unit]
Description=Kill Bill Billing API
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=killbill
Group=killbill
EnvironmentFile=/etc/killbill/killbill.env
WorkingDirectory=/var/lib/killbill
ExecStart=/usr/bin/java $JAVA_OPTS -jar /opt/killbill/killbill.war
Restart=on-failure
RestartSec=10
SuccessExitStatus=143
LimitNOFILE=65535
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/killbill /var/log/killbill /tmp
UMask=0027

[Install]
WantedBy=multi-user.target
EOF_SYSTEMD
sudo systemctl daemon-reload
sudo systemctl enable killbill
```

Sebelum start produksi, pastikan migrasi schema sudah dijalankan sesuai mekanisme rilis yang Anda pilih. Jalankan migrasi dengan user `killbill_migration`, lalu jalankan aplikasi dengan user `killbill_app`.

```bash
sudo systemctl start killbill
sudo systemctl status killbill --no-pager
journalctl -u killbill -n 200 --no-pager
```

### 14.8. Nginx reverse proxy dengan header keamanan

Pasang sertifikat TLS dari CA tepercaya, misalnya melalui ACME/Let’s Encrypt atau sertifikat perusahaan. Contoh server block:

```bash
sudo tee /etc/nginx/sites-available/killbill.conf >/dev/null <<'EOF_NGINX'
server {
    listen 80;
    server_name billing.example.net;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name billing.example.net;

    ssl_certificate /etc/ssl/certs/billing.example.net.crt;
    ssl_certificate_key /etc/ssl/private/billing.example.net.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    client_max_body_size 2m;
    proxy_connect_timeout 10s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
EOF_NGINX
sudo ln -sfn /etc/nginx/sites-available/killbill.conf /etc/nginx/sites-enabled/killbill.conf
sudo nginx -t
sudo systemctl reload nginx
```

Untuk production multi-node, konfigurasi TLS dan rate limit idealnya berada di load balancer/WAF terpusat, lalu Nginx host hanya menerima traffic dari load balancer.

### 14.9. Rate limiting dan fail2ban baseline

Tambahkan rate limit Nginx untuk mengurangi brute force dan abuse API:

```bash
sudo tee /etc/nginx/conf.d/killbill-rate-limit.conf >/dev/null <<'EOF_RATE'
limit_req_zone $binary_remote_addr zone=kb_api:10m rate=10r/s;
EOF_RATE
```

Tambahkan di blok `location /` sebelum `proxy_pass`:

```nginx
limit_req zone=kb_api burst=50 nodelay;
```

Validasi dan reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 14.10. Backup dan restore database

Contoh backup harian terenkripsi dengan retensi lokal singkat. Untuk produksi, kirim hasil backup ke object storage terenkripsi dan aktifkan PITR/binlog.

```bash
sudo install -d -o root -g root -m 0700 /var/backups/killbill
sudo tee /usr/local/sbin/backup-killbill-db.sh >/dev/null <<'EOF_BACKUP'
#!/usr/bin/env bash
set -euo pipefail
DATE="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="/var/backups/killbill/killbill-${DATE}.sql.gz"
mysqldump --single-transaction --routines --triggers --events killbill | gzip -9 > "${OUT}"
chmod 0600 "${OUT}"
find /var/backups/killbill -type f -name 'killbill-*.sql.gz' -mtime +7 -delete
EOF_BACKUP
sudo chmod 0750 /usr/local/sbin/backup-killbill-db.sh
sudo tee /etc/systemd/system/backup-killbill-db.service >/dev/null <<'EOF_BSVC'
[Unit]
Description=Backup Kill Bill database

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/backup-killbill-db.sh
EOF_BSVC
sudo tee /etc/systemd/system/backup-killbill-db.timer >/dev/null <<'EOF_BTMR'
[Unit]
Description=Run Kill Bill DB backup daily

[Timer]
OnCalendar=*-*-* 02:15:00 UTC
Persistent=true

[Install]
WantedBy=timers.target
EOF_BTMR
sudo systemctl daemon-reload
sudo systemctl enable --now backup-killbill-db.timer
systemctl list-timers --all | grep backup-killbill
```

Uji restore ke server staging:

```bash
gunzip -c /var/backups/killbill/killbill-YYYYMMDDTHHMMSSZ.sql.gz | mysql killbill_restore
```

Backup yang belum pernah diuji restore belum boleh dianggap valid.

### 14.11. Monitoring wajib sebelum go-live

Minimal pasang node exporter dan database exporter sesuai stack monitoring Anda. Jika memakai Prometheus, baseline yang wajib dipantau:

- `systemd` status `killbill`, `nginx`, dan `mariadb`.
- CPU, RAM, disk, inode, network, dan file descriptor.
- JVM heap, GC pause, thread count, dan HTTP latency.
- Database connection, lock wait, slow query, disk growth, dan backup age.
- Metrik bisnis: invoice generated, payment failure, overdue account, suspended account, dan provisioning failure.

Tambahkan alert produksi sebelum go-live:

```text
- Kill Bill service down > 1 menit
- HTTP 5xx > 1% selama 5 menit
- p95 latency API > 2 detik selama 10 menit
- Disk database > 80%
- Backup terakhir > 26 jam
- Payment callback failure naik tajam dari baseline
```

### 14.12. Verifikasi smoke test setelah service aktif

Jalankan dari jaringan admin, bukan dari internet publik:

```bash
curl -k -I https://${KILLBILL_HOSTNAME}/
systemctl is-active killbill
systemctl is-active nginx
systemctl is-active mariadb
journalctl -u killbill -p err -n 50 --no-pager
```

Lanjutkan dengan test bisnis melalui API atau tool internal:

1. Buat tenant test.
2. Buat account test.
3. Buat subscription paket ISP termurah.
4. Generate invoice test.
5. Simulasikan pembayaran sukses dan gagal di gateway sandbox.
6. Pastikan event provisioning suspend/resume tidak ganda saat retry.
7. Hapus atau tutup data test sesuai prosedur audit.

### 14.13. Checklist produksi final di Ubuntu

- [ ] Versi Ubuntu terverifikasi sebagai 24.04 LTS atau versi LTS yang didukung.
- [ ] Semua paket OS sudah update dan reboot terakhir sudah selesai.
- [ ] Java runtime sesuai versi Kill Bill yang dipakai.
- [ ] Artifact Kill Bill dipin, checksum diverifikasi, dan tidak menggunakan tag `latest`.
- [ ] Service berjalan sebagai user non-login `killbill`.
- [ ] Secret berada di `/etc/killbill/killbill.env` dengan permission `0640` atau di secret manager.
- [ ] Database memakai user migrasi dan user runtime terpisah.
- [ ] Database hanya bind ke jaringan privat atau localhost.
- [ ] Firewall hanya membuka port yang diperlukan.
- [ ] HTTPS aktif dengan sertifikat valid.
- [ ] Rate limit aktif di Nginx/load balancer/WAF.
- [ ] Backup timer aktif dan restore sudah diuji di staging.
- [ ] Monitoring dan alert sudah mengirim notifikasi ke tim operasional.
- [ ] Smoke test teknis dan skenario billing ISP selesai tanpa error.

## 15. Runbook incident singkat

### Dugaan SQL injection atau abuse API

1. Aktifkan mode proteksi di WAF/load balancer untuk pattern yang terdeteksi.
2. Rotasi credential yang mungkin terekspos.
3. Nonaktifkan credential integrasi yang mencurigakan.
4. Ambil snapshot log aplikasi, access log, audit database, dan slow query log.
5. Identifikasi endpoint, tenant, account, dan query yang terdampak.
6. Patch plugin/integrasi yang rentan.
7. Jalankan integrity check terhadap invoice, payment, dan subscription yang dibuat selama window incident.
8. Dokumentasikan root cause dan tambahkan regression test.

### Gangguan invoice atau payment

1. Bekukan retry otomatis yang dapat membuat efek ganda bila akar masalah belum jelas.
2. Bandingkan event Kill Bill, payment gateway, dan ledger internal.
3. Jalankan rekonsiliasi berbasis idempotency key dan external payment ID.
4. Pulihkan backlog secara bertahap setelah penyebab diperbaiki.

## 16. Prinsip stabilitas operasional

- Jangan melakukan perubahan manual langsung di database produksi kecuali melalui runbook yang disetujui.
- Jangan deploy perubahan catalog besar tanpa simulasi invoice massal.
- Pisahkan tugas billing, provisioning, dan reporting agar kegagalan satu sistem tidak menjatuhkan seluruh operasi ISP.
- Semua proses retry harus punya batas, backoff, dan idempotency key.
- Semua integrasi uang dan jaringan harus punya audit trail yang bisa direkonsiliasi.
