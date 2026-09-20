# 📬 MailIDS — Self-Hosted Enterprise Mail Server & Webmail Portal

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12" />
  <img src="https://img.shields.io/badge/Livewire-v4_SPA-4e56a6?style=for-the-badge&logo=livewire&logoColor=white" alt="Livewire 4" />
  <img src="https://img.shields.io/badge/Postfix-MTA_Engine-blue?style=for-the-badge&logo=linux&logoColor=white" alt="Postfix" />
  <img src="https://img.shields.io/badge/Dovecot-IMAP_POP3-green?style=for-the-badge&logo=linux&logoColor=white" alt="Dovecot" />
  <img src="https://img.shields.io/badge/Security-SPF_DKIM_DMARC-00b4d8?style=for-the-badge&logo=cloudflare&logoColor=white" alt="Email Security" />
</p>

Sistem Mail Server mandiri (*self-hosted*) berstandar industri berbasis Linux (Postfix & Dovecot) dengan antarmuka ganda (**Web Portal Admin** dan **Webmail Client Modern**) bertenaga Laravel 12 & Livewire SPA.

---

## 🌟 Fitur Utama

### 1. Web Portal Admin (Manajemen Server)
* **Multi-Domain Virtual Hosting:** Kelola banyak domain bisnis dalam satu server mail (`virtual_domains`).
* **Akun Email & Kuota Terpusat:** Buat mailbox pengguna dengan alokasi kuota penyimpanan dinamis (MB/GB) dan hash password kompatibel Dovecot (`BLF-CRYPT/BCRYPT`).
* **Alias & Forwarding:** Pengalihan email otomatis antar-alamat internal maupun eksternal.
* **Generator DNS Record:** Pembuat panduan instan A Record, MX, SPF, DKIM (OpenDKIM 2048-bit), DMARC, dan Reverse DNS (PTR).
* **Simulator Deliverability (Mail-Tester 10/10):** Uji kesiapan DNS secara nyata (*Live DNS Query*) untuk memastikan email masuk ke Primary Inbox Gmail/Outlook.
* **1-Click Server Installer & Log Monitor:** Setup otomatis Postfix/Dovecot di VPS dan pemantau log realtime `/var/log/mail.log`.

### 2. Webmail Client (Pengguna Mailbox)
* **Desain 3-Kolom Modern:** Terinspirasi dari estetika Gmail & Hostinger Webmail dalam antarmuka gelap (*Dark Mode Glassmorphism*).
* **Dukungan Draf Cerdas:** Penyimpanan otomatis saat modal ditutup, lanjutkan draf lama, buang draf (*discard*), dan notifikasi badge Draf.
* **Manajemen SPAM Transparan:** Banner penjelasan alasan spam ala Gmail dengan kalkulasi skor reputasi pengirim, tombol *"Bukan Spam"*, *"Hapus Selamanya"*, dan *"Kosongkan Semua Spam"*.
* **Folder Kustom Dinamis:** Buat dan hapus folder kustom kapan saja dengan fitur proteksi migrasi otomatis email kembali ke Kotak Masuk.
* **Indikator Storage Real-Time:** Menghitung ukuran fisik Maildir aktual dan memberi peringatan saat kuota mendekati penuh.

---

## 🚀 Panduan Deployment VPS Linux

Panduan lengkap instalasi di server VPS Ubuntu (22.04 / 24.04 LTS), mulai dari spesifikasi VPS, setup port 25, instalasi Postfix, Dovecot, OpenDKIM, SSL Let's Encrypt, hingga konfigurasi DNS dapat dibaca langsung di:

👉 **[BACA PANDUAN DEPLOYMENT VPS LENGKAP (DEPLOYMENT_VPS_GUIDE.md)](DEPLOYMENT_VPS_GUIDE.md)**

---

## 💻 Instalasi Lokal (Development / Demo)

1. **Clone repository:**
   ```bash
   git clone https://github.com/risky-budiman/mail-server.git
   cd mail-server
   ```

2. **Install Dependensi PHP & JS:**
   ```bash
   composer install
   npm install
   npm run build
   ```

3. **Setup Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   ```

4. **Jalankan Aplikasi:**
   ```bash
   php artisan serve
   ```
   * Portal Admin: `http://localhost:8000/admin/login` (Akun: `admin@mailportal.local` / `Secret123!`)
   * Webmail: `http://localhost:8000/webmail/login` (Akun: `admin@perusahaan.net.id` / `Secret123!`)

---

## 📄 Lisensi
Proyek ini dilisensikan di bawah lisensi [MIT](LICENSE).
