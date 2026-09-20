# 🚀 Panduan Lengkap Deployment Mail Server & Web Portal (MailIDS) ke VPS Production

Dokumen ini berisi panduan *step-by-step* yang komprehensif untuk memasang, mengonfigurasi, dan meluncurkan sistem Mail Server mandiri (Postfix + Dovecot) beserta Web Portal Admin & Webmail Client Laravel di VPS Linux Ubuntu (22.04 / 24.04 LTS).

---

## 📋 Daftar Isi
1. [Spesifikasi Minimum VPS](#1-spesifikasi-minimum-vps)
2. [Checklist Pra-Instalasi (Sebelum Sentuh VPS)](#2-checklist-pra-instalasi)
3. [Langkah 1: Persiapan VPS Baru & Instalasi Paket Sistem Dasar (OS Packages)](#3-langkah-1-persiapan-vps-baru--instalasi-paket-sistem-dasar-os-packages)
4. [Langkah 2: Setup Hostname & Reverse DNS (rDNS/PTR)](#4-langkah-2-setup-hostname--reverse-dns-rdnsptr)
5. [Langkah 3: Instalasi & Konfigurasi Engine Mail Server (Postfix + Dovecot)](#5-langkah-3-instalasi--konfigurasi-engine-mail-server-postfix--dovecot)
6. [Langkah 4: Menyiapkan Web Portal Laravel di Nginx & PHP 8.2+](#6-langkah-4-menyiapkan-web-portal-laravel-di-nginx)
7. [Langkah 5: Setup Sertifikat SSL Gratis (Let's Encrypt / Certbot)](#7-langkah-5-setup-sertifikat-ssl-gratis-lets-encrypt)
8. [Langkah 6: Konfigurasi DNS di Registrar Domain (Cloudflare, dll)](#8-langkah-6-konfigurasi-dns-di-registrar-domain)
9. [Langkah 7: Pengujian Skor Email 10/10 (Masuk Inbox)](#9-langkah-7-pengujian-skor-email-1010-masuk-inbox)
10. [Perawatan & Troubleshooting](#10-perawatan--troubleshooting)

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

---

## 3. Langkah 1: Persiapan VPS Baru & Instalasi Paket Sistem Dasar (OS Packages)

Saat Anda baru pertama kali membeli VPS Ubuntu kosong, jalankan perintah berikut untuk menginstal semua paket dasar (Web Server, Database, PHP 8.2+, Node.js, Composer, dan Git):

### A. Update Sistem & Install Utilitas Dasar
```bash
sudo apt-get update -y && sudo apt-get upgrade -y
sudo apt-get install -y curl wget git unzip zip software-properties-common ca-certificates gnupg lsb-release ufw htop
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

### C. Install PHP 8.2 / 8.3 & Ekstensi yang Dibutuhkan Laravel
```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update -y
sudo apt-get install -y php8.2 php8.2-fpm php8.2-cli php8.2-common php8.2-mysql php8.2-sqlite3 \
    php8.2-zip php8.2-gd php8.2-mbstring php8.2-curl php8.2-xml php8.2-bcmath php8.2-intl \
    php8.2-readline php8.2-imap php8.2-soap
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

Proyek ini telah dilengkapi skrip instalasi otomatis siap pakai: [setup-mail-server.sh](file:///d:/AI%20Code/mailids/scripts/setup-mail-server.sh).

### 💡 Kapan dan dari Mana Skrip Ini Dijalankan?
- **Kapan Dijalankan?**
  Skrip ini dijalankan **SATU KALI SAJA** pada awal setup server, yaitu tepat setelah Anda selesai menginstal paket dasar OS (Langkah 1) dan membuat database MySQL `mailportal` serta menyetel Hostname VPS (Langkah 2).
- **Dari Mana Dijalankan?**
  Dijalankan langsung dari **Terminal SSH di VPS Anda**, tepat di dalam folder root aplikasi (`/var/www/mailids`).

---

### A. Unggah / Kloning Folder Proyek ke VPS
Login ke VPS via SSH dari komputer lokal Anda:
```bash
ssh root@103.180.200.15
```
Di dalam terminal VPS, buat folder dan unggah/kloning proyek:
```bash
mkdir -p /var/www/mailids
cd /var/www/mailids
# Kloning proyek Anda atau unggah file proyek ke sini
```

### B. Cara Menjalankan Instalasi Mail Engine (Pilih Salah Satu)

Anda memiliki **3 pilihan cara yang sangat fleksibel**:

#### Pilihan 1: Jalankan Perintah Paket Manual (Jika Ingin Install Paket Satu per Satu)
Jika Anda ingin melihat atau mengeksekusi langsung paket instalasi Postfix & Dovecot dari apt:
```bash
# 1. Update repositori
sudo apt-get update -y

# 2. Install Postfix & Modul MySQL
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postfix postfix-mysql

# 3. Install Dovecot Core, IMAP, POP3, LMTP & Driver MySQL
sudo apt-get install -y dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql

# 4. Install OpenDKIM & Firewall UFW
sudo apt-get install -y opendkim opendkim-tools ufw
```

---

#### Pilihan 2: Eksekusi Skrip Otomasi Terpadu (Direkomendasikan - Paling Cepat & Akurat)
Skrip `setup-mail-server.sh` di dalam folder proyek sudah merangkum seluruh perintah `apt-get` di atas sekaligus **mengonfigurasi file Postfix (`main.cf`), Dovecot (`dovecot-sql.conf.ext`), Maildir storage (`/var/vmail/`), dan OpenDKIM** secara otomatis dalam 1 menit:
```bash
# Berikan izin eksekusi
chmod +x scripts/setup-mail-server.sh

# Jalankan skrip dengan parameter domain & password MySQL Anda:
sudo DOMAIN="perusahaan.co.id" DB_PASS="StrongSecretPassword123!" bash scripts/setup-mail-server.sh
```
> **Catatan:** Ganti `perusahaan.co.id` dengan domain bisnis asli Anda, dan `StrongSecretPassword123!` dengan password user MySQL `mailuser` yang Anda buat pada Langkah 1.

---

#### Pilihan 3: Menggunakan Tombol Menu Web Portal (1-Click UI)
1. Buka Web Portal Admin Anda di browser: `http://IP_VPS_ANDA/admin/login`
2. Klik menu **"1-Click Server Installer"** di bilah navigasi kiri.
3. Masukkan domain bisnis Anda dan klik tombol **"Jalankan Instalasi Mail Engine Sekarang"**.
4. Konsol log interaktif di layar akan menampilkan proses instalasi Postfix, Dovecot, OpenDKIM, dan Firewall secara real-time sampai selesai.

---

**Rincian Komponen Paket Mail Server yang Diinstal:**
1. **Postfix (MTA Engine):**
   - Menangani pengiriman (*outbound*) dan penerimaan (*inbound*) email di port 25, 587, dan 465.
   - Terintegrasi dengan modul `postfix-mysql` untuk membaca akun dan domain langsung dari database.
2. **Dovecot (IMAP/POP3 & SASL Auth):**
   - Paket `dovecot-core`, `dovecot-imapd`, `dovecot-pop3d`, dan `dovecot-lmtpd`.
   - Mengelola kotak surat (*Maildir*) dan sinkronisasi email ke Thunderbird, Outlook, smartphone, dan Webmail di port 993 (SSL).
   - Menyediakan jembatan autentikasi SASL socket (`/var/spool/postfix/private/auth`) agar Postfix hanya mengizinkan pengiriman email dari user yang memiliki akun terdaftar.
3. **OpenDKIM & OpenDKIM-Tools:**
   - Menandatangani (*digital signature*) setiap email keluar dengan kunci kriptografi RSA agar tidak dianggap email penipuan/spoofing oleh Google Gmail dan Yahoo.
4. **User Sistem & Storage Maildir:**
   - Membuat user sistem terisolasi `vmail:vmail` (UID/GID 5000) dengan struktur direktori fisik `/var/vmail/%d/%n`.
5. **Firewall Rules (UFW):**
   - Membuka port: `25` (SMTP), `587` (Submission), `465` (SMTPS), `993` (IMAPS), `80` (HTTP), dan `443` (HTTPS).

---

## 6. Langkah 4: Menyiapkan Web Portal Laravel di Nginx

### A. Environment & Dependensi
Di folder `/var/www/mailids`, buat file `.env`:
```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader
npm run build
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

Set hak akses direktori Laravel:
```bash
chown -R www-data:www-data /var/www/mailids/storage /var/www/mailids/bootstrap/cache
chmod -R 775 /var/www/mailids/storage /var/www/mailids/bootstrap/cache
```

### B. Konfigurasi Nginx Web Server
Buat file virtual host Nginx:
```bash
nano /etc/nginx/sites-available/mailids.conf
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
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Sesuaikan versi PHP Anda
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
Aktifkan konfigurasi dan reload Nginx:
```bash
ln -s /etc/nginx/sites-available/mailids.conf /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

---

## 6. Langkah 4: Setup Sertifikat SSL Gratis (Let's Encrypt)

Amankan web portal dan engine mail server dengan sertifikat SSL resmi:
```bash
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d mail.perusahaan.co.id
```

Tautkan sertifikat Let's Encrypt ke Postfix dan Dovecot:
```bash
# Untuk Postfix
postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/mail.perusahaan.co.id/fullchain.pem"
postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/mail.perusahaan.co.id/privkey.pem"
postconf -e "smtpd_use_tls = yes"

# Untuk Dovecot (/etc/dovecot/conf.d/10-ssl.conf)
sed -i 's|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/mail.perusahaan.co.id/fullchain.pem|' /etc/dovecot/conf.d/10-ssl.conf
sed -i 's|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/mail.perusahaan.co.id/privkey.pem|' /etc/dovecot/conf.d/10-ssl.conf

systemctl restart postfix dovecot
```

---

## 7. Langkah 5: Konfigurasi DNS di Registrar Domain

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

## 8. Langkah 6: Pengujian Skor Email 10/10 (Masuk Inbox)

1. Buka situs [https://www.mail-tester.com](https://www.mail-tester.com).
2. Salin alamat email sementara yang diberikan (misal: `test-xyz123@mail-tester.com`).
3. Buka Webmail Anda di `https://mail.perusahaan.co.id/webmail`, tulis pesan baru ke alamat uji coba tersebut.
4. Klik **Periksa Skor Anda** di situs Mail-Tester.
5. Anda akan mendapatkan skor **10/10 (Sempurna)** karena SPF, DKIM, DMARC, dan Reverse DNS sudah terverifikasi penuh. Email Anda dijamin langsung masuk Inbox Gmail dan Outlook tanpa masuk folder Spam!

---

## 9. Perawatan & Troubleshooting

### Memantau Log Server
Untuk melihat aktivitas pengiriman atau mendeteksi kendala, Anda dapat memantau langsung melalui panel admin pada menu **Mail Logs & Engine Monitor** atau via terminal:
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
Gunakan perintah artisan yang sudah kita siapkan:
```bash
php artisan mail:backup
```
File cadangan berformat JSON akan tersimpan di `storage/app/backups/`.

---
*Dokumentasi ini siap digunakan kapan saja begitu VPS Anda aktif!*
