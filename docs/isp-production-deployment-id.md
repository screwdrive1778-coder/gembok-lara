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

## 14. Runbook incident singkat

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

## 15. Prinsip stabilitas operasional

- Jangan melakukan perubahan manual langsung di database produksi kecuali melalui runbook yang disetujui.
- Jangan deploy perubahan catalog besar tanpa simulasi invoice massal.
- Pisahkan tugas billing, provisioning, dan reporting agar kegagalan satu sistem tidak menjatuhkan seluruh operasi ISP.
- Semua proses retry harus punya batas, backoff, dan idempotency key.
- Semua integrasi uang dan jaringan harus punya audit trail yang bisa direkonsiliasi.
