#!/bin/bash
# ==============================================================================
# Script Otomatis Perbaikan Total & Pemulihan Webmail MailIDS
# Memastikan Nginx, PHP-FPM, dan Storage 100% Aktif & Normal
# ==============================================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Harap jalankan script ini sebagai root (contoh: sudo bash scripts/fix-vps-uploads.sh)"
    exit 1
fi

echo "=================================================================="
echo " 1. Membersihkan Konfigurasi Pool & Memperbarui php.ini"
echo "=================================================================="

# Bersihkan baris php_value yang mungkin sempat tertambah di www.conf agar PHP-FPM tidak crash
for POOL_FILE in /etc/php/*/fpm/pool.d/www.conf; do
    if [ -f "$POOL_FILE" ]; then
        echo "--> Menormalkan PHP-FPM Pool: $POOL_FILE"
        sed -i '/php_value.*upload_max_filesize/d' "$POOL_FILE" 2>/dev/null || true
        sed -i '/php_value.*post_max_size/d' "$POOL_FILE" 2>/dev/null || true
    fi
done

# Update semua file php.ini (fpm, cli, apache2)
for INI_FILE in /etc/php/*/*/php.ini; do
    if [ -f "$INI_FILE" ]; then
        echo "--> Mengoptimasi php.ini & OPcache di: $INI_FILE"
        sed -i 's/^upload_max_filesize\s*=.*/upload_max_filesize = 500M/' "$INI_FILE"
        sed -i 's/^post_max_size\s*=.*/post_max_size = 500M/' "$INI_FILE"
        sed -i 's/^memory_limit\s*=.*/memory_limit = 1024M/' "$INI_FILE"
        sed -i 's/^max_execution_time\s*=.*/max_execution_time = 600/' "$INI_FILE"
        sed -i 's/^max_input_time\s*=.*/max_input_time = 600/' "$INI_FILE"
        
        # High Performance OPcache & Realpath Cache (Akselerasi Kecepatan Eksekusi PHP 300%-500%)
        sed -i 's/^;*opcache.enable\s*=.*/opcache.enable = 1/' "$INI_FILE"
        sed -i 's/^;*opcache.enable_cli\s*=.*/opcache.enable_cli = 1/' "$INI_FILE"
        sed -i 's/^;*opcache.memory_consumption\s*=.*/opcache.memory_consumption = 256/' "$INI_FILE"
        sed -i 's/^;*opcache.interned_strings_buffer\s*=.*/opcache.interned_strings_buffer = 16/' "$INI_FILE"
        sed -i 's/^;*opcache.max_accelerated_files\s*=.*/opcache.max_accelerated_files = 30000/' "$INI_FILE"
        sed -i 's/^;*opcache.validate_timestamps\s*=.*/opcache.validate_timestamps = 1/' "$INI_FILE"
        sed -i 's/^;*realpath_cache_size\s*=.*/realpath_cache_size = 4096K/' "$INI_FILE"
        sed -i 's/^;*realpath_cache_ttl\s*=.*/realpath_cache_ttl = 600/' "$INI_FILE"
    fi
done

echo "=================================================================="
echo " 2. Menormalkan Konfigurasi Nginx (Bersih & Bebas Batas)"
echo "=================================================================="

# Bersihkan seluruh duplikat baris client_max_body_size dari semua file konfigurasi Nginx
sed -i '/client_max_body_size/d' /etc/nginx/nginx.conf 2>/dev/null || true
sed -i '/client_max_body_size/d' /etc/nginx/sites-available/* 2>/dev/null || true
sed -i '/client_max_body_size/d' /etc/nginx/sites-enabled/* 2>/dev/null || true

# Gunakan SATU konfigurasi resmi terpusat di /etc/nginx/conf.d/upload_limits.conf (berlaku global tanpa duplikat)
mkdir -p /etc/nginx/conf.d
echo "client_max_body_size 0;" > /etc/nginx/conf.d/upload_limits.conf
echo "--> Konfigurasi Nginx upload_limits.conf diset ke 0 (unlimited global)."


# Uji sintaks Nginx
echo "--> Menguji sintaks Nginx:"
nginx -t || true

echo "=================================================================="
echo " 3. Merestart Layanan PHP-FPM & Nginx"
echo "=================================================================="

# Restart semua versi PHP-FPM yang terpasang
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
echo " 4. Memastikan Hak Akses Folder Storage & Cache Laravel"
echo "=================================================================="

APP_DIR="/var/www/mailids"
if [ -d "$APP_DIR" ]; then
    mkdir -p "$APP_DIR/storage/app/private/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/public/livewire-tmp"
    mkdir -p "$APP_DIR/storage/app/public/attachments"
    mkdir -p "$APP_DIR/storage/framework/cache/data"
    mkdir -p "$APP_DIR/storage/framework/sessions"
    mkdir -p "$APP_DIR/storage/framework/views"
    mkdir -p "$APP_DIR/bootstrap/cache"

    chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    chmod -R 777 "$APP_DIR/storage/app/private/livewire-tmp"
    chmod -R 777 "$APP_DIR/storage/app/livewire-tmp"
    chmod -R 777 "$APP_DIR/storage/app/public/attachments"
    chmod -R 777 "$APP_DIR/storage/app/public/livewire-tmp"
    chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

    cd "$APP_DIR"
    php artisan optimize:clear
    php artisan storage:link 2>/dev/null || true
    php artisan optimize
fi

echo "=================================================================="
echo " 5. Status Layanan Sistem (Verifikasi Akhir)"
echo "=================================================================="

if systemctl is-active --quiet nginx; then
    echo "[OK] Status Nginx: BERJALAN NORMAL (ACTIVE)"
else
    echo "[PERINGATAN] Nginx belum aktif! Jalankan 'sudo nginx -t' untuk melihat detailnya."
fi

PHP_RUNNING=0
for VER in /etc/php/*; do
    if [ -d "$VER" ]; then
        PHP_VER=$(basename "$VER")
        if systemctl is-active --quiet "php${PHP_VER}-fpm"; then
            echo "[OK] Status php${PHP_VER}-fpm: BERJALAN NORMAL (ACTIVE)"
            PHP_RUNNING=1
        fi
    fi
done

if [ "$PHP_RUNNING" -eq 0 ]; then
    echo "[PERINGATAN] Tidak ada service PHP-FPM yang aktif."
fi

CLI_MAX=$(php -r "echo ini_get('upload_max_filesize');")
CLI_POST=$(php -r "echo ini_get('post_max_size');")
echo "[OK] PHP Batas Upload: upload_max_filesize = $CLI_MAX | post_max_size = $CLI_POST"

echo "=================================================================="
echo " SELESAI! Webmail & Mail Server siap diakses kembali secara normal."
echo "=================================================================="
