#!/bin/sh
# Membuat "isian" (Environment variables) stack IT Asset & ITERA untuk Portainer.
#   sh deploy/portainer/buat-isian-asset.sh [berkas-keluaran]
# Hasil disimpan di folder pengguna (BUKAN di repo) dengan izin 600. Nilai rahasia dibuat acak; yang berasal dari sistem lain
# (PORTAL_SSO_SECRET, HRIS_API_TOKEN) diberi tanda ISI_DARI_... dan WAJIB diganti sebelum ditempel ke Portainer.
# Dijalankan ulang: nilai rahasia yang sudah ada DIPAKAI ULANG (DB_PASSWORD hanya berlaku saat volume database pertama dibuat).
set -eu
cd "$(dirname "$0")/../.."
OUT="${1:-$HOME/akp-asset-isian.txt}"
get() { [ -f "$OUT" ] && grep -E "^$1=" "$OUT" | head -1 | cut -d= -f2- || true; }
keep() { v="$(get "$1")"; if [ -n "$v" ] && ! echo "$v" | grep -q '^ISI_'; then echo "$v"; else echo "$2"; fi; }

APP_KEY="$(keep APP_KEY "base64:$(openssl rand -base64 32)")"
DB_PASSWORD="$(keep DB_PASSWORD "$(openssl rand -hex 16)")"
SIMA_API_TOKEN="$(keep SIMA_API_TOKEN "$(openssl rand -hex 32)")"
if [ -n "$(get VAPID_PUBLIC_KEY)" ]; then VPUB="$(get VAPID_PUBLIC_KEY)"; VPRIV="$(get VAPID_PRIVATE_KEY)"
else
  K="$(php -r 'require "vendor/autoload.php"; $k = Minishlink\WebPush\VAPID::createVapidKeys(); echo $k["publicKey"], " ", $k["privateKey"];')"
  VPUB="${K%% *}"; VPRIV="${K##* }"
fi

umask 077
cat > "$OUT" <<ISIAN
# Isian stack IT Asset & ITERA untuk Portainer (Add stack > Environment variables > Advanced mode). JANGAN di-commit.
# Dibuat $(date '+%Y-%m-%d %H:%M'). Ganti semua nilai berawalan ISI_ sebelum dipakai.
ASSET_URL=$(keep ASSET_URL https://asset.lameruru.web.id)
ASSET_PORT=$(keep ASSET_PORT 8116)
ASSET_TAG=latest
APP_KEY=$APP_KEY
DB_PASSWORD=$DB_PASSWORD
DB_HOST_PORT=$(keep DB_HOST_PORT 5446)
PORTAL_URL=$(keep PORTAL_URL https://portal.lameruru.web.id)
PORTAL_APP_CODE=asset
PORTAL_SSO_SECRET=$(keep PORTAL_SSO_SECRET ISI_DARI_PORTAL_MODUL_ASSET_INTEGRASI)
SSO_ENABLED=true
SSO_ALLOW_LOCAL_LOGIN=true
HRIS_API_URL=$(keep HRIS_API_URL http://host.docker.internal:8101)
HRIS_API_TOKEN=$(keep HRIS_API_TOKEN ISI_DARI_TOKEN_LAYANAN_HRIS)
SIMA_API_TOKEN=$SIMA_API_TOKEN
VAPID_PUBLIC_KEY=$VPUB
VAPID_PRIVATE_KEY=$VPRIV
VAPID_SUBJECT=mailto:noreply@akp.co.id
TELEGRAM_BOT_TOKEN=$(get TELEGRAM_BOT_TOKEN)
TELEGRAM_ADMIN_CHAT_ID=$(get TELEGRAM_ADMIN_CHAT_ID)
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
ISIAN
chmod 600 "$OUT"
echo "Isian disimpan: $OUT"
grep -n '=ISI_' "$OUT" | sed 's/^/  PERLU DIISI  /' || true
