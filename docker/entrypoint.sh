#!/bin/sh
# Dijalankan setiap kontainer start. Pengaturan (.env) datang dari environment kontainer, bukan dari image.
set -e
cd /var/www/html

# Peran layanan (web / antrian / penjadwal) memakai pengaturan penuh. Perintah lain (mis. key:generate, tinker, hris:sync-employees)
# langsung dijalankan apa adanya sebagai www-data.
case "$1 $2 $3" in
    "apache2-foreground  "|"php artisan queue:work"|"php artisan schedule:work") ;;
    *) exec runuser -u www-data -- "$@" ;;
esac

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY belum diisi. Buat dengan: docker run --rm <image> php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/private storage/app/public
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
# tautan publik (foto aset, lampiran): storage/app/public -> public/storage
# public/ dimiliki root: symlink harus dibuat sebagai root (jika tidak, foto/berkas publik 404)
[ -e public/storage ] || php artisan storage:link || echo "peringatan: storage:link gagal" >&2

# Konfigurasi dikunci dari environment kontainer ini. Route tidak di-cache karena ada route berbentuk closure.
runuser -u www-data -- php artisan config:cache
runuser -u www-data -- php artisan view:cache || echo "peringatan: view:cache dilewati" >&2

# Hanya satu kontainer (web) yang boleh memigrasi, supaya tidak berebut.
if [ "$AUTO_MIGRATE" = "true" ] && [ "$1" = "apache2-foreground" ]; then
    runuser -u www-data -- php artisan migrate --force
    # peran/izin (Admin, Staff IT, User): idempoten, tanpa akun demo
    runuser -u www-data -- php artisan db:seed --class='Database\Seeders\RolesSeeder' --force
fi

if [ "$1" = "apache2-foreground" ]; then
    exec "$@"
fi
exec runuser -u www-data -- "$@"
