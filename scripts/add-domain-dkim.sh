#!/bin/bash
# ==============================================================================
# MailIDS - Otomatisasi Registrasi Multi-Domain OpenDKIM
# Script ini dipanggil otomatis saat domain baru dibuat di Admin Portal
# ==============================================================================

DOMAIN="$1"

if [ -z "$DOMAIN" ]; then
    echo "Usage: $0 <domain-name>"
    exit 1
fi

KEY_DIR="/etc/opendkim/keys/${DOMAIN}"
mkdir -p "${KEY_DIR}"
mkdir -p /etc/opendkim

# 1. Generate RSA 2048-bit keypair jika belum ada
if [ ! -f "${KEY_DIR}/default.private" ]; then
    opendkim-genkey -b 2048 -d "${DOMAIN}" -D "${KEY_DIR}" -s default
    chown -R opendkim:opendkim "${KEY_DIR}"
    chmod 600 "${KEY_DIR}/default.private"
fi

# 2. Daftarkan ke SigningTable
touch /etc/opendkim/SigningTable
if ! grep -q "*@${DOMAIN}" /etc/opendkim/SigningTable; then
    echo "*@${DOMAIN} default._domainkey.${DOMAIN}" >> /etc/opendkim/SigningTable
fi

# 3. Daftarkan ke KeyTable
touch /etc/opendkim/KeyTable
if ! grep -q "default._domainkey.${DOMAIN}" /etc/opendkim/KeyTable; then
    echo "default._domainkey.${DOMAIN} ${DOMAIN}:default:${KEY_DIR}/default.private" >> /etc/opendkim/KeyTable
fi

# 4. Daftarkan ke TrustedHosts
touch /etc/opendkim/TrustedHosts
if ! grep -q "^${DOMAIN}$" /etc/opendkim/TrustedHosts; then
    echo "${DOMAIN}" >> /etc/opendkim/TrustedHosts
fi

chown -R opendkim:opendkim /etc/opendkim

# 5. Reload OpenDKIM tanpa restart downtime
systemctl reload-or-restart opendkim
systemctl reload-or-restart postfix

echo "SUCCESS: Domain ${DOMAIN} successfully registered with OpenDKIM."
