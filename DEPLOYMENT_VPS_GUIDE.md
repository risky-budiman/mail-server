# 🚀 Panduan Lengkap Deployment Mail Server & Web Portal (MailIDS) ke VPS On-Premise / Production

Dokumen ini berisi panduan *step-by-step* yang komprehensif untuk memasang, mengonfigurasi, dan meluncurkan sistem Mail Server mandiri (Postfix + Dovecot) beserta Web Portal Admin & Webmail Client Laravel di VPS Linux Ubuntu (22.04 / 24.04 LTS).

---

## 📋 Daftar Isi
1. [Spesifikasi Minimum VPS](#1-spesifikasi-minimum-vps)
2. [Checklist Pra-Instalasi (Sebelum Sentuh VPS)](#2-checklist-pra-instalasi)
3. [Langkah 1: Persiapan VPS Baru & Instalasi Paket Sistem Dasar (OS Packages)](#3-langkah-1-persiapan-vps-baru--instalasi-paket-sistem-dasar-os-packages)
4. [Langkah 2: Setup Hostname & Reverse DNS (rDNS/PTR)](#4-langkah-2-setup-hostname--reverse-dns-rdnsptr)
5. [Langkah 3: Instalasi & Konfigurasi Engine Mail Server (Postfix + Dovecot + OpenDKIM)](#5-langkah-3-instalasi--konfigurasi-engine-mail-server-postfix--dovecot--opendkim)
6. [Langkah 4: Menyiapkan Web Portal Laravel & Webmail di Nginx](#6-langkah-4-menyiapkan-web-portal-laravel--webmail-di-nginx)
7. [Langkah 5: Hak Akses Sudoer Khusus Fitur 1-Click Installer UI & Log Viewer](#7-langkah-5-hak-akses-sudoer-khusus-fitur-1-click-installer-ui--log-viewer)
8. [Langkah 6: Setup Sertifikat SSL Gratis (Let's Encrypt / Certbot)](#8-langkah-6-setup-sertifikat-ssl-gratis-lets-encrypt)
9. [Langkah 7: Konfigurasi DNS di Registrar Domain (Cloudflare, dll)](#9-langkah-7-konfigurasi-dns-di-registrar-domain)
10. [Langkah 8: Pengujian Skor Email 10/10 (Masuk Inbox)](#10-langkah-8-pengujian-skor-email-1010-masuk-inbox)
11. [Langkah 9: Panduan Migrasi Akun Email Lama (Hostinger / cPanel via IMAPSync)](#11-langkah-9-panduan-migrasi-akun-email-lama-hostinger--cpanel-via-imapsync)
12. [Perawatan, Queue Worker, & Troubleshooting](#12-perawatan-queue-worker--troubleshooting)

---

## 1. Spesifikasi Minimum VPS

Untuk menjalankan Mail Server mandiri dengan lancar:
- **Sistem Operasi:** Ubuntu 22.04 LTS atau Ubuntu 24.04 LTS (Direkomendasikan).
- **RAM:** Minimal **2 GB** (Disarankan 4 GB jika ingin menyalakan antivirus ClamAV & SpamAssassin).
- **CPU:** 1-2 vCPU.
- **Penyimpanan:** Minimal 25 GB NVMe / SSD (Tergantung kapasitas kuota mailbox yang diinginkan).
- **Penting (Port 25):** Pastikan provider VPS Anda **tidak memblokir Port 25 Outbound**. 
  > *Rekomendasi Provider yang Mengizinkan Port 25:* Hetzner (request unblock), Linode/Akamai (request ticket), DigitalOcean (ajukan unblock ticket), OVHcloud, Contabo, atau Vultr.

---

## 2. Checklist Pra-Instalasi

Sebelum memulai, siapkan hal-hal berikut:
1. **Domain Aktif:** Misalnya `perusahaan.co.id` atau `bisnisanda.com`.
2. **IP Publik Statis VPS:** Misalnya `103.180.200.15`.
3. **Subdomain Mail Server:** Tentukan FQDN server, standar internasional: `mail.perusahaan.co.id`.

---

## 3. Langkah 1: Persiapan VPS Baru & Instalasi Paket Sistem Dasar (OS Packages)

Saat Anda baru pertama kali membeli VPS Ubuntu kosong, jalankan perintah berikut untuk menginstal semua paket dasar (Web Server, Database, PHP 8.2/8.3, Node.js, Composer, Git, dan alat migrasi `imapsync`):

### A. Update Sistem & Install Utilitas Dasar + IMAPSync
```bash
sudo apt-get update -y && sudo apt-get upgrade -y
sudo apt-get install -y curl wget git unzip zip software-properties-common ca-certificates gnupg lsb-release ufw htop imapsync
```

### B. Install Nginx Web Server & Database MariaDB / MySQL
```bash
sudo apt-get install -y nginx mariadb-server mariadb-client
sudo systemctl enable nginx mariadb
sudo systemctl start nginx mariadb
```

Jalankan pengamanan database MariaDB:
```bash
sudo mysql_secure_installation
```
*(Ikuti petunjuk di layar: buat password root MySQL Anda).*

Buat database untuk Mail Portal:
```bash
sudo mysql -u root -p -e "
CREATE DATABASE mailportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailuser'@'localhost' IDENTIFIED BY 'StrongSecretPassword123!';
GRANT ALL PRIVILEGES ON mailportal.* TO 'mailuser'@'localhost';
FLUSH PRIVILEGES;
"
```

### C. Install PHP 8.2 & Ekstensi yang Dibutuhkan Laravel & Webmail

> [!WARNING]
> **Penyebab Error `unmet dependencies: libc6 (>= 2.35), libssl3`:**
> Ubuntu 20.04 (Focal) menggunakan library sistem versi lama (`libc6 2.31` dan `libssl 1.1`). Sementara PHP 8.2 di Linux modern mewajibkan `libc6 2.35+` dan `libssl3`. Mengarahkan PPA ke jammy tidak bisa dipaksakan karena file inti OS (glibc) tidak cocok.
>
> Karena itu, **satu-satunya solusi yang benar, bersih, dan stabil untuk Mail Server & Laravel** adalah beralih ke **Ubuntu 22.04 LTS (Jammy)** atau **Ubuntu 24.04 LTS (Noble)**.

---

#### 🌟 Cara 1: Rebuild / Reinstall OS dari Panel VPS (Paling Cepat - 2 Menit)
Jika VPS ini baru dibeli dan belum ada website lain yang berjalan:
1. Buka Dashboard Web Hosting VPS Anda (misal Contabo, DigitalOcean, Hetzner, Linode, Niagahoster, dll).
2. Cari menu **OS Reinstall** / **Rebuild**.
3. Pilih **Ubuntu 22.04 LTS (64-bit)** atau **Ubuntu 24.04 LTS**.
4. Klik Reinstall. Dalam 1-2 menit server baru yang bersih siap digunakan.

---

#### 🌟 Cara 2: Upgrade Langsung dari Terminal VPS Tanpa Install Ulang (In-Place Upgrade)
Jika Anda tidak ingin reset VPS dan ingin langsung meng-upgrade Ubuntu 20.04 ke Ubuntu 22.04 lewat SSH:

1. Kembalikan repository apt ke kondisi bersih:
```bash
sudo rm -f /etc/apt/sources.list.d/*php*.list
sudo apt-get update -y && sudo apt-get upgrade -y
```

2. Jalankan perintah resmi upgrade versi Ubuntu:
```bash
sudo do-release-upgrade
```
*(Tekan **Enter** atau ketik **y** saat sistem meminta konfirmasi proses upgrade).*

3. Setelah proses selesai dan VPS reboot, login kembali via SSH. Cek versi OS:
```bash
lsb_release -a
```
*(Pastikan sudah tertulis Ubuntu 22.04 Jammy)*.

---

#### 🌟 Setelah di Ubuntu 22.04+: Jalankan Instalasi PHP 8.2 dengan Mulus
```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update -y
sudo apt-get install -y php8.2 php8.2-fpm php8.2-cli php8.2-common php8.2-mysql php8.2-sqlite3 \
    php8.2-zip php8.2-gd php8.2-mbstring php8.2-curl php8.2-xml php8.2-bcmath php8.2-intl \
    php8.2-readline php8.2-imap php8.2-soap
```

Verifikasi:
```bash
php -v
sudo systemctl status php8.2-fpm
```

### D. Install Composer & Node.js (Vite Asset Builder)
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js (v20 LTS) & NPM
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

---

## 4. Langkah 2: Setup Hostname & Reverse DNS (rDNS/PTR)

### A. Ubah Hostname di Terminal VPS
Masuk ke terminal VPS via SSH (`ssh root@ip-vps-anda`), lalu jalankan:
```bash
hostnamectl set-hostname mail.perusahaan.co.id
echo "mail.perusahaan.co.id" > /etc/mailname
```

Edit file `/etc/hosts`:
```bash
nano /etc/hosts
```
Tambahkan baris berikut di paling atas:
```text
127.0.0.1 localhost
103.180.200.15 mail.perusahaan.co.id mail
```

### B. Setup Reverse DNS (PTR Record)
Buka panel provider VPS Anda (misal panel Contabo, Hetzner, atau DigitalOcean) pada menu **IP Management / Reverse DNS**.
- Arahkan IP publik VPS Anda ke `mail.perusahaan.co.id`.
- *Catatan: PTR Record adalah syarat mutlak agar email tidak langsung ditolak oleh server Google (Gmail).*

---

## 5. Langkah 3: Instalasi & Konfigurasi Engine Mail Server (Postfix + Dovecot + OpenDKIM)

Proyek ini telah dilengkapi skrip instalasi otomatis siap pakai: `scripts/setup-mail-server.sh`.

### A. Unggah / Kloning Folder Proyek ke VPS
Login ke VPS via SSH dari komputer lokal Anda:
```bash
ssh root@103.180.200.15
```
Di dalam terminal VPS, kloning repository resmi dari GitHub ke folder `/var/www/mailids`:
```bash
# Pastikan git terinstall dan kloning repository
sudo git clone https://github.com/risky-budiman/mail-server.git /var/www/mailids
cd /var/www/mailids
```

### B. Cara Menjalankan Instalasi Mail Engine (Pilih Salah Satu)

#### Pilihan 1: Eksekusi Skrip Otomasi Terpadu (Direkomendasikan - Paling Cepat & Akurat)
Skrip `setup-mail-server.sh` di dalam folder proyek sudah merangkum seluruh perintah `apt-get` sekaligus **mengonfigurasi file Postfix (`main.cf`), Dovecot (`dovecot-sql.conf.ext`), Maildir storage (`/var/vmail/`), OpenDKIM, dan penyesuaian hak akses log grup `adm`** secara otomatis dalam 1 menit:
```bash
# Berikan izin eksekusi
chmod +x scripts/setup-mail-server.sh

# Jalankan skrip dengan parameter domain & password MySQL Anda:
sudo DOMAIN="perusahaan.co.id" DB_PASS="StrongSecretPassword123!" bash scripts/setup-mail-server.sh
```
> **Catatan:** Ganti `perusahaan.co.id` dengan domain bisnis asli Anda, dan `StrongSecretPassword123!` dengan password user MySQL `mailuser` yang Anda buat pada Langkah 1.

---

#### Pilihan 2: Menggunakan Tombol Menu Web Portal (1-Click UI)
1. Siapkan terlebih dahulu web portal (Langkah 4) & hak akses sudoer (Langkah 5).
2. Buka Web Portal Admin Anda di browser: `http://IP_VPS_ANDA/admin/login` (atau via nama domain).
3. Klik menu **"1-Click Server Installer"** di bilah navigasi kiri.
4. Masukkan domain bisnis Anda dan klik tombol **"Jalankan Instalasi Mail Engine Sekarang"**.
5. Konsol log interaktif di layar akan menampilkan proses instalasi Postfix, Dovecot, OpenDKIM, dan Firewall secara real-time sampai selesai.

---

## 6. Langkah 4: Menyiapkan Web Portal Laravel & Webmail di Nginx

### A. Environment Production (.env)
Di folder `/var/www/mailids`, buat atau sesuaikan file `.env`:
```bash
cp .env.example .env
nano .env
```
Pastikan variabel kunci disetel untuk mode produksi:
```ini
APP_NAME="MailIDS Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mail.perusahaan.co.id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailportal
DB_USERNAME=mailuser
DB_PASSWORD=StrongSecretPassword123!

QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Konfigurasi SMTP Outbound Postfix Lokal
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="no-reply@perusahaan.co.id"
MAIL_FROM_NAME="MailIDS System"

# Konfigurasi IMAP Dovecot Lokal untuk Webmail Client
IMAP_HOST=127.0.0.1
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
IMAP_VALIDATE_CERT=false
```

Jalankan instalasi dependensi, migrasi, dan build aset:
```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
```

Set hak akses direktori Laravel:
```bash
chown -R www-data:www-data /var/www/mailids
chmod -R 775 /var/www/mailids/storage /var/www/mailids/bootstrap/cache
```

### B. Konfigurasi Nginx Web Server
Buat file virtual host Nginx:
```bash
sudo nano /etc/nginx/sites-available/mailids.conf
```
Isi konfigurasi berikut:
```nginx
server {
    listen 80;
    server_name mail.perusahaan.co.id;
    root /var/www/mailids/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Sesuaikan jika menggunakan php8.3-fpm
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
Aktifkan konfigurasi dan reload Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/mailids.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 7. Langkah 5: Hak Akses Sudoer Khusus Fitur 1-Click Installer UI & Log Viewer

Agar fitur **1-Click Server Installer UI** dan **Log Viewer** di portal admin dapat mengeksekusi skrip engine dan membaca `/var/log/mail.log` tanpa hambatan izin permission Linux:

### A. Izinkan `www-data` Membaca Log Sistem
```bash
sudo usermod -a -G adm www-data
```

### B. Setup Sudoers Khusus untuk Installer & Postfix Reload
Buat file konfigurasi sudoers khusus untuk user web server:
```bash
sudo visudo -f /etc/sudoers.d/mailids-portal
```
Masukkan baris berikut:
```text
www-data ALL=(ALL) NOPASSWD: /bin/bash /var/www/mailids/scripts/setup-mail-server.sh
www-data ALL=(ALL) NOPASSWD: /usr/sbin/postconf
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload postfix
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload dovecot
```
Simpan file tersebut dan atur izinnya:
```bash
sudo chmod 0440 /etc/sudoers.d/mailids-portal
```

---

## 8. Langkah 6: Setup Sertifikat SSL Gratis (Let's Encrypt / Certbot)

Amankan web portal dan engine mail server dengan sertifikat SSL resmi:
```bash
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d mail.perusahaan.co.id
```

Tautkan sertifikat Let's Encrypt ke Postfix dan Dovecot:
```bash
# Untuk Postfix
sudo postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/mail.perusahaan.co.id/fullchain.pem"
sudo postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/mail.perusahaan.co.id/privkey.pem"
sudo postconf -e "smtpd_use_tls = yes"

# Untuk Dovecot (/etc/dovecot/conf.d/10-ssl.conf)
sudo sed -i 's|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/mail.perusahaan.co.id/fullchain.pem|' /etc/dovecot/conf.d/10-ssl.conf
sudo sed -i 's|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/mail.perusahaan.co.id/privkey.pem|' /etc/dovecot/conf.d/10-ssl.conf

sudo systemctl restart postfix dovecot
```

---

## 9. Langkah 7: Konfigurasi DNS di Registrar Domain

Buka panel DNS domain Anda (Cloudflare, Niagahoster, Domainesia, dsb.), lalu masukkan tabel DNS yang sudah digenerate otomatis oleh portal di menu **DNS & Security Guide**:

| Tipe | Nama / Host | Nilai / Target Content | Prioritas | Fungsi |
| :--- | :--- | :--- | :---: | :--- |
| **A** | `mail` | `103.180.200.15` (IP VPS Anda) | - | Mengarahkan mail server |
| **MX** | `@` | `mail.perusahaan.co.id.` | 10 | Penerimaan email |
| **TXT** | `@` | `v=spf1 mx a:mail.perusahaan.co.id ip4:103.180.200.15 ~all` | - | SPF Anti-Spoofing |
| **TXT** | `default._domainkey` | `v=DKIM1; k=rsa; p=MIIBIjANBg...` *(Salin dari portal)* | - | Digital Signature |
| **TXT** | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:postmaster@perusahaan.co.id` | - | DMARC Policy (Gmail 2024+) |

> **Catatan Penting di Cloudflare:** Untuk record `A` (`mail.perusahaan.co.id`), pastikan Proxy Status diatur ke **DNS Only** (Awan Abu-abu), bukan Proxied (Awan Oranye), agar koneksi port SMTP (25, 587) dan IMAP (993) tidak terblokir oleh proxy Cloudflare.

---

## 10. Langkah 8: Pengujian Skor Email 10/10 (Masuk Inbox)

1. Buka situs [https://www.mail-tester.com](https://www.mail-tester.com).
2. Salin alamat email sementara yang diberikan (misal: `test-xyz123@mail-tester.com`).
3. Buka Webmail Anda di `https://mail.perusahaan.co.id/webmail`, tulis pesan baru ke alamat uji coba tersebut.
4. Klik **Periksa Skor Anda** di situs Mail-Tester.
5. Anda akan mendapatkan skor **10/10 (Sempurna)** karena SPF, DKIM, DMARC, dan Reverse DNS sudah terverifikasi penuh. Email Anda dijamin langsung masuk Inbox Gmail dan Outlook tanpa masuk folder Spam!

---

## 11. Langkah 9: Panduan Migrasi Akun Email Lama (Hostinger / cPanel via IMAPSync)

Jika Anda memindahkan mailbox dari cPanel / Hostinger:

1. Buka menu **"Email Migration Tool"** di Admin Web Portal (`/admin/migration`).
2. Masukkan kredensial IMAP server lama (contoh: `imap.hostinger.com`, Port 993, SSL) dan pilih akun target lokal yang sudah dibuat di menu Manajemen User.
3. Klik **"Uji Koneksi & Mulai Sinkronisasi"**.
4. Atau jika ingin migrasi massal seluruh mailbox via terminal VPS menggunakan `imapsync`:
```bash
imapsync \
  --host1 imap.hostinger.com --user1 user@perusahaan.co.id --pass1 "PasswordLama" --ssl1 \
  --host2 127.0.0.1 --user2 user@perusahaan.co.id --pass2 "PasswordBaru" --ssl2
```

---

## 12. Perawatan, Queue Worker, & Troubleshooting

### Setup Supervisor untuk Antrean (Queue Worker)
Agar sinkronisasi migrasi dan pengiriman antrean berjalan otomatis di latar belakang, pasang Supervisor:
```bash
sudo apt-get install -y supervisor
```
Buat file konfigurasi `/etc/supervisor/conf.d/mailids-worker.conf`:
```ini
[program:mailids-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mailids/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/mailids/storage/logs/worker.log
stopwaitsecs=3600
```
Jalankan supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mailids-worker:*
```

### Memantau Log Server
Anda dapat memantau langsung melalui panel admin pada menu **Mail Logs & Engine Monitor** (`/admin/logs`) atau via terminal:
```bash
tail -f /var/log/mail.log
```

### Memeriksa Antrean Email (Queue)
```bash
# Melihat email yang sedang antre
mailq

# Memaksa pengiriman ulang antrean
postfix flush

# Menghapus seluruh antrean yang macet
postsuper -d ALL
```

### Backup Konfigurasi & Data Akun
Gunakan perintah artisan:
```bash
php artisan mail:backup
```
File cadangan berformat JSON akan tersimpan di `storage/app/backups/`.

---
*Dokumentasi ini 100% mutakhir dan selaras dengan seluruh modul MailIDS yang aktif.*
