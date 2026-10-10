#!/bin/bash
# ==============================================================================
# Script Otomasi Instalasi Core Mail Engine (Postfix, Dovecot, MySQL Virtuals)
# Kompatibel: Ubuntu 22.04 / 24.04 LTS
# ==============================================================================

set -e

# ==============================================================================
# Variabel Konfigurasi (Bisa diatur sebelum menjalankan script)
# Contoh: DOMAIN=perusahaan.co.id DB_PASS=Rahasia123 sudo bash setup-mail-server.sh
# ==============================================================================
DOMAIN="${DOMAIN:-domain.net.id}"
HOSTNAME="${HOSTNAME:-mail.${DOMAIN}}"
DB_NAME="${DB_NAME:-mailportal}"
DB_USER="${DB_USER:-mailuser}"
DB_PASS="${DB_PASS:-StrongSecretPassword123!}"

echo ">>> Menjalankan Setup Mail Engine untuk: ${HOSTNAME}"
echo ">>> Database: ${DB_NAME} (User: ${DB_USER})"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y postfix postfix-mysql dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql opendkim opendkim-tools ufw

# Install imapsync secara graceful jika tersedia di repositori sistem
apt-get install -y imapsync 2>/dev/null || true

# Pastikan www-data dapat membaca log mail sistem (/var/log/mail.log) jika Nginx terpasang
usermod -a -G adm www-data || true

echo "======================================================"
echo " 2. Konfigurasi Hostname Server"
echo "======================================================"
hostnamectl set-hostname ${HOSTNAME}
echo "${HOSTNAME}" > /etc/mailname

echo "======================================================"
echo " 3. Setup Direktori Maildir & User vmail"
echo "======================================================"
groupadd -g 5000 vmail || true
useradd -g vmail -u 5000 vmail -d /var/vmail -m || true
mkdir -p /var/vmail
chown -R vmail:vmail /var/vmail
chmod -R 770 /var/vmail

echo "======================================================"
echo " 4. Konfigurasi Lookup MySQL untuk Postfix"
echo "======================================================"
# 4a. Lookup Virtual Domains
cat <<EOF > /etc/postfix/mysql-virtual-mailbox-domains.cf
user = ${DB_USER}
password = ${DB_PASS}
hosts = 127.0.0.1
dbname = ${DB_NAME}
query = SELECT 1 FROM virtual_domains WHERE name='%s' AND is_active=1
EOF

# 4b. Lookup Virtual Mailbox Users
cat <<EOF > /etc/postfix/mysql-virtual-mailbox-maps.cf
user = ${DB_USER}
password = ${DB_PASS}
hosts = 127.0.0.1
dbname = ${DB_NAME}
query = SELECT maildir_path FROM virtual_users WHERE email='%s' AND is_active=1
EOF

# 4c. Lookup Virtual Aliases
cat <<EOF > /etc/postfix/mysql-virtual-alias-maps.cf
user = ${DB_USER}
password = ${DB_PASS}
hosts = 127.0.0.1
dbname = ${DB_NAME}
query = SELECT destination_email FROM virtual_aliases WHERE source_email='%s' AND is_active=1
EOF

chmod 640 /etc/postfix/mysql-virtual-*.cf
chgrp postfix /etc/postfix/mysql-virtual-*.cf

echo "======================================================"
echo " 5. Konfigurasi Postfix & SASL via Dovecot"
echo "======================================================"
postconf -e "myhostname = ${HOSTNAME}"
postconf -e "mydestination = localhost"
postconf -e "virtual_mailbox_domains = mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf"
postconf -e "virtual_mailbox_maps = mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf"
postconf -e "virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf"
postconf -e "virtual_mailbox_base = /var/vmail"
postconf -e "virtual_uid_maps = static:5000"
postconf -e "virtual_gid_maps = static:5000"

# SASL Authentication untuk SMTP Kirim Email via Dovecot
postconf -e "smtpd_sasl_type = dovecot"
postconf -e "smtpd_sasl_path = private/auth"
postconf -e "smtpd_sasl_auth_enable = yes"
postconf -e "smtpd_recipient_restrictions = permit_sasl_authenticated,permit_mynetworks,reject_unauth_destination"
postconf -e "smtpd_relay_restrictions = permit_mynetworks,permit_sasl_authenticated,defer_unauth_destination"
postconf -e "alias_maps = hash:/etc/aliases"
postconf -e "alias_database = hash:/etc/aliases"

# Aktifkan port SMTP Submission (587) dan SMTPS (465) di master.cf
sed -i -E 's/^#?(submission\s+inet\s+n\s+-\s+y\s+-\s+-\s+smtpd)/\1/' /etc/postfix/master.cf
sed -i -E 's/^#?(submissions\s+inet\s+n\s+-\s+y\s+-\s+-\s+smtpd)/\1/' /etc/postfix/master.cf
sed -i -E 's/^#?(smtps\s+inet\s+n\s+-\s+y\s+-\s+-\s+smtpd)/\1/' /etc/postfix/master.cf

# Batas Ukuran Pesan & Lampiran Email (Default 50 MB)
postconf -e "message_size_limit = 52428800"
postconf -e "virtual_mailbox_limit = 0"
postconf -e "mailbox_size_limit = 0"

echo "======================================================"
echo " 6. Konfigurasi Dovecot (Auth, Mailbox & Socket SASL)"
echo "======================================================"
cat <<EOF > /etc/dovecot/dovecot-sql.conf.ext
driver = mysql
connect = host=127.0.0.1 dbname=${DB_NAME} user=${DB_USER} password=${DB_PASS}
default_pass_scheme = BLF-CRYPT
password_query = SELECT email as user, password FROM virtual_users WHERE email='%u' AND is_active=1
user_query = SELECT 5000 AS uid, 5000 AS gid, concat('/var/vmail/', maildir_path) AS home FROM virtual_users WHERE email='%u' AND is_active=1
EOF

chmod 600 /etc/dovecot/dovecot-sql.conf.ext
chown root:root /etc/dovecot/dovecot-sql.conf.ext

# Konfigurasi Dovecot 10-master.cf untuk socket SASL Postfix
cat << 'EOF' > /etc/dovecot/conf.d/10-master.conf
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
}
EOF

# Konfigurasi Mailbox Storage Dovecot
cat << 'EOF' > /etc/dovecot/conf.d/10-mail.conf
mail_location = maildir:/var/vmail/%d/%n
mail_uid = 5000
mail_gid = 5000
first_valid_uid = 5000
last_valid_uid = 5000
EOF

echo "======================================================"
echo " 7. Setup OpenDKIM (Tanda Tangan Digital Anti-Spam)"
echo "======================================================"
mkdir -p /etc/opendkim/keys/${DOMAIN}
mkdir -p /run/opendkim
chown -R opendkim:opendkim /etc/opendkim
chown -R opendkim:opendkim /run/opendkim

# Generate key pair OpenDKIM jika belum ada
if [ ! -f "/etc/opendkim/keys/${DOMAIN}/default.private" ]; then
    opendkim-genkey -b 2048 -d "${DOMAIN}" -D "/etc/opendkim/keys/${DOMAIN}" -s default
    chown -R opendkim:opendkim "/etc/opendkim/keys/${DOMAIN}"
    chmod 600 "/etc/opendkim/keys/${DOMAIN}/default.private"
fi

cat << EOF > /etc/opendkim/SigningTable
*@${DOMAIN} default._domainkey.${DOMAIN}
EOF

cat << EOF > /etc/opendkim/KeyTable
default._domainkey.${DOMAIN} ${DOMAIN}:default:/etc/opendkim/keys/${DOMAIN}/default.private
EOF

cat << 'EOF' > /etc/opendkim/TrustedHosts
127.0.0.1
localhost
EOF
echo "${HOSTNAME}" >> /etc/opendkim/TrustedHosts
echo "${DOMAIN}" >> /etc/opendkim/TrustedHosts

cat << 'EOF' > /etc/opendkim.conf
AutoRestart             Yes
AutoRestartRate         10/1h
UMask                   002
Syslog                  Yes
SyslogSuccess           Yes
LogWhy                  Yes
Canonicalization        relaxed/simple
Mode                    sv
SubDomains              no
OversignHeaders         From
UserID                  opendkim:opendkim
PidFile                 /run/opendkim/opendkim.pid
Socket                  inet:12301@127.0.0.1
RequireSafeKeys         false

KeyTable                /etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
ExternalIgnoreList      refile:/etc/opendkim/TrustedHosts
InternalHosts           refile:/etc/opendkim/TrustedHosts
EOF

# Pastikan override default socket tidak bentrok
mkdir -p /etc/default
cat << 'EOF' > /etc/default/opendkim
SOCKET="inet:12301@127.0.0.1"
EOF

# Hubungkan Postfix ke OpenDKIM Milter
postconf -e "milter_default_action = accept"
postconf -e "milter_protocol = 6"
postconf -e "smtpd_milters = inet:127.0.0.1:12301"
postconf -e "non_smtpd_milters = inet:127.0.0.1:12301"

echo "======================================================"
echo " 8. Firewall Rules (UFW)"
echo "======================================================"
ufw allow 25/tcp    # SMTP Outbound/Inbound
ufw allow 587/tcp   # SMTP Submission (STARTTLS)
ufw allow 465/tcp   # SMTPS SSL
ufw allow 993/tcp   # IMAPS (Dovecot)
ufw allow 80/tcp    # HTTP (Web Portal / Let's Encrypt)
ufw allow 443/tcp   # HTTPS (Web Portal)

echo "======================================================"
echo " 9. Optimasi Upload PHP-FPM & Hak Akses Storage Webmail"
echo "======================================================"
# Naikkan batas upload_max_filesize dan post_max_size di seluruh versi PHP-FPM
for PHP_INI in /etc/php/*/fpm/php.ini; do
    if [ -f "$PHP_INI" ]; then
        echo ">>> Menyesuaikan batas upload di: $PHP_INI"
        sed -i 's/^upload_max_filesize\s*=.*/upload_max_filesize = 100M/' "$PHP_INI"
        sed -i 's/^post_max_size\s*=.*/post_max_size = 100M/' "$PHP_INI"
        sed -i 's/^memory_limit\s*=.*/memory_limit = 256M/' "$PHP_INI"
    fi
done

# Pastikan folder upload Livewire & attachments berizin www-data
if [ -d "/var/www/mailids" ]; then
    mkdir -p /var/www/mailids/storage/app/private/livewire-tmp
    mkdir -p /var/www/mailids/storage/app/livewire-tmp
    mkdir -p /var/www/mailids/storage/app/public/attachments
    chown -R www-data:www-data /var/www/mailids/storage /var/www/mailids/bootstrap/cache
    chmod -R 775 /var/www/mailids/storage /var/www/mailids/bootstrap/cache
fi

echo "======================================================"
echo " Selesai! Mengaktifkan & Menjalankan Semua Layanan:"
echo "======================================================"
systemctl restart postfix dovecot opendkim
systemctl enable postfix dovecot opendkim

for VER in /etc/php/*; do
    if [ -d "$VER" ]; then
        PHP_VER=$(basename "$VER")
        systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || service "php${PHP_VER}-fpm" restart 2>/dev/null || true
    fi
done
systemctl restart nginx 2>/dev/null || true
echo " Mail Engine & Webmail Storage Telah Siap Digunakan!"


