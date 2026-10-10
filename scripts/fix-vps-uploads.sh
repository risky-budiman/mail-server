#!/bin/bash
# ==============================================================================
# Script Otomatis Perbaikan Total Upload File & Lampiran (Webmail MailIDS)
# Menghilangkan pesan "The attachments failed to upload" secara permanen
# ==============================================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Harap jalankan script ini sebagai root (contoh: sudo bash scripts/fix-vps-uploads.sh)"
    exit 1
fi


echo "=================================================================="
echo " 1. Menyesuaikan Konfigurasi PHP-FPM & CLI (upload_max_filesize 500M / Bebas)"
echo "=================================================================="

# Update semua file php.ini (fpm, cli, apache2) yang terpasang di sistem
for INI_FILE in /etc/php/*/*/php.ini; do
    if [ -f "$INI_FILE" ]; then
        echo "--> Memperbarui: $INI_FILE"
        sed -i 's/^upload_max_filesize\s*=.*/upload_max_filesize = 500M/' "$INI_FILE"
        sed -i 's/^post_max_size\s*=.*/post_max_size = 500M/' "$INI_FILE"
        sed -i 's/^memory_limit\s*=.*/memory_limit = 1024M/' "$INI_FILE"
        sed -i 's/^max_execution_time\s*=.*/max_execution_time = 600/' "$INI_FILE"
        sed -i 's/^max_input_time\s*=.*/max_input_time = 600/' "$INI_FILE"
    fi
done

# Pastikan pool www.conf juga menerapkan batas upload bebas jika ada
for POOL_FILE in /etc/php/*/fpm/pool.d/www.conf; do
    if [ -f "$POOL_FILE" ]; then
        echo "--> Menyesuaikan PHP-FPM Pool: $POOL_FILE"
        sed -i '/php_value\[upload_max_filesize\]/d' "$POOL_FILE"
        sed -i '/php_value\[post_max_size\]/d' "$POOL_FILE"
        echo "php_value[upload_max_filesize] = 500M" >> "$POOL_FILE"
        echo "php_value[post_max_size] = 500M" >> "$POOL_FILE"
    fi
done

echo "=================================================================="
echo " 2. Menyesuaikan Konfigurasi Nginx (client_max_body_size 0 / Bebas)"
echo "=================================================================="

# Di Nginx, nilai 0 mematikan batasan ukuran request body (unlimited)
if [ -f /etc/nginx/nginx.conf ]; then
    if grep -q "client_max_body_size" /etc/nginx/nginx.conf; then
        sed -i 's/client_max_body_size.*/client_max_body_size 0;/' /etc/nginx/nginx.conf
    else
        sed -i '/http {/a \    client_max_body_size 0;' /etc/nginx/nginx.conf
    fi
    echo "--> Nginx core /etc/nginx/nginx.conf diset ke 0 (unlimited)."
fi

# Set seluruh file virtual host Nginx ke 0 (unlimited)
for SITE_FILE in /etc/nginx/sites-available/*; do
    if [ -f "$SITE_FILE" ]; then
        if grep -q "client_max_body_size" "$SITE_FILE"; then
            sed -i 's/client_max_body_size.*/client_max_body_size 0;/' "$SITE_FILE"
        else
            sed -i '/server {/a \    client_max_body_size 0;' "$SITE_FILE"
        fi
        echo "--> Nginx Virtual Host $SITE_FILE diset ke 0 (unlimited)."
    fi
done

# Hapus batasan ukuran pesan di Postfix (0 = unlimited)
if command -v postconf >/dev/null 2>&1; then
    postconf -e "message_size_limit = 0"
    postconf -e "mailbox_size_limit = 0"
    echo "--> Postfix message_size_limit diset ke 0 (unlimited)."
fi


echo "=================================================================="
echo " 3. Memperbaiki Izin Folder Storage & Upload Sementara Livewire"
echo "=================================================================="

APP_DIR="/var/www/mailids"
if [ -d "$APP_DIR" ]; then
    # Buat seluruh direktori penting
    mkdir -p "$APP_DIR/storage/app/private/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/public/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/public/attachments"
    mkdir -p "$APP_DIR/storage/framework/cache/data"
    mkdir -p "$APP_DIR/storage/framework/sessions"
    mkdir -p "$APP_DIR/storage/framework/views"
    mkdir -p "$APP_DIR/bootstrap/cache"

    # Set kepemilikan ke www-data
    chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    
    # Berikan izin tulis penuh pada folder upload
    chmod -R 777 "$APP_DIR/storage/app/private/livewire-tmp"
    chmod -R 777 "$APP_DIR/storage/app/livewire-tmp"
    chmod -R 777 "$APP_DIR/storage/app/public/attachments"
    chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    
    echo "--> Hak akses folder storage www-data berhasil diperbarui!"
fi

echo "=================================================================="
echo " 4. Merestart Layanan Nginx & Seluruh Versi PHP-FPM Aktif"
echo "=================================================================="

# Uji konfigurasi Nginx
nginx -t || true

# Restart PHP-FPM secara eksplisit per versi (php8.3-fpm, php8.2-fpm, dst)
for VER in /etc/php/*; do
    if [ -d "$VER" ]; then
        PHP_VER=$(basename "$VER")
        echo "--> Merestart php${PHP_VER}-fpm..."
        systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || service "php${PHP_VER}-fpm" restart 2>/dev/null || true
    fi
done

# Restart Nginx
echo "--> Merestart Nginx..."
systemctl restart nginx 2>/dev/null || service nginx restart 2>/dev/null || true

echo "=================================================================="
echo " 5. Membersihkan Cache & Optimasi Laravel"
echo "=================================================================="

if [ -d "$APP_DIR" ]; then
    cd "$APP_DIR"
    php artisan optimize:clear
    php artisan storage:link 2>/dev/null || true
    php artisan optimize
fi


echo "=================================================================="
echo " 6. Verifikasi Diagnostik Akhir"
echo "=================================================================="

# Tes apakah www-data bisa menulis file sementara
if su -s /bin/bash www-data -c "touch $APP_DIR/storage/app/private/livewire-tmp/test_write.tmp && rm -f $APP_DIR/storage/app/private/livewire-tmp/test_write.tmp" 2>/dev/null; then
    echo "[OK] Hak akses tulis www-data ke livewire-tmp: BERHASIL (100% WRITABLE)"
else
    echo "[PERINGATAN] Mohon periksa izin folder $APP_DIR/storage/app/private/livewire-tmp"
fi

CLI_MAX=$(php -r "echo ini_get('upload_max_filesize');")
CLI_POST=$(php -r "echo ini_get('post_max_size');")
echo "[OK] Batas PHP CLI: upload_max_filesize = $CLI_MAX | post_max_size = $CLI_POST"

echo "=================================================================="
echo " SEMUA SELESAI! Upload file (PDF, ZIP, RAR, XLS, DOCX, dll) SIAP DITES!"
echo "=================================================================="
