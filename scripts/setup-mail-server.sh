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
mkdir -p /etc/opendkim/keys
chown -R opendkim:opendkim /etc/opendkim
chmod -R 700 /etc/opendkim/keys

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
Socket                  inet:12301@localhost
EOF

# Hubungkan Postfix ke OpenDKIM Milter
postconf -e "milter_default_action = accept"
postconf -e "milter_protocol = 6"
postconf -e "smtpd_milters = inet:localhost:12301"
postconf -e "non_smtpd_milters = inet:localhost:12301"

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
echo " Selesai! Mengaktifkan & Menjalankan Semua Layanan:"
echo "======================================================"
systemctl restart postfix dovecot opendkim
systemctl enable postfix dovecot opendkim
echo " Mail Engine (Postfix + Dovecot + OpenDKIM) Telah Aktif!"
