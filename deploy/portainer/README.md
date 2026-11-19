# Deploy ke server Portal (Portainer) - IT Asset & ITERA

Pola sama dengan aplikasi AKP lain (PTW, Lapor GA, ...): GitHub Actions membangun image ke `ghcr.io/eddyyucca/akp-asset`, Portainer menarik image `latest`.

## Sekali di awal

1. **Portal** > Admin > Modul > *Tambah modul*: kode `asset`, nama "IT Asset & ITERA", URL `https://asset.lameruru.web.id`. Salin **rahasia SSO** dari halaman Integrasi, lalu *Deteksi level* (admin / staff / user) setelah aplikasi berjalan, dan beri akses ke pengguna.
2. **HRIS**: pastikan token layanan HRIS tersedia (`HRIS_API_TOKEN`). Buat token acak untuk `SIMA_API_TOKEN`; di `.env` HRIS isi `SIMA_URL=https://asset.lameruru.web.id` dan `SIMA_API_TOKEN` yang sama (panel aset di offboarding HRIS langsung memakai aplikasi ini).
3. **Portainer** > Add stack > *Repository*: `https://github.com/eddyyucca/SIMA-V2-AKP`, compose path `deploy/portainer/docker-compose.git.yml`. Isi Environment variables:

| Variabel | Isi |
|---|---|
| `APP_KEY` | `docker run --rm ghcr.io/eddyyucca/akp-asset php artisan key:generate --show` |
| `DB_PASSWORD` | kata sandi baru (hanya berlaku saat volume database pertama kali dibuat) |
| `PORTAL_SSO_SECRET` | dari Portal > Modul > asset > Integrasi |
| `HRIS_API_TOKEN`, `SIMA_API_TOKEN` | lihat langkah 2 |
| opsional | `ASSET_URL`, `ASSET_PORT` (8116), `PORTAL_URL`, `HRIS_API_URL`, `VAPID_*`, `TELEGRAM_*` |

4. Reverse proxy: arahkan `asset.lameruru.web.id` ke port 8116 (HTTPS di proxy; `TRUSTED_PROXIES=*` sudah bawaan).
5. Pertama kali jalan: `AUTO_MIGRATE=true` memigrasi database dan mengisi peran (Admin, Staff IT, User). **Tidak ada akun demo.** Admin pertama: login lewat Portal sebagai super admin (otomatis Admin).
6. Sinkron awal karyawan: di kontainer `asset` jalankan `php artisan hris:sync-employees` (simulasi), lalu `--apply`.

## Auto-deploy (opsional)

`gh variable set AUTO_DEPLOY --body true -R eddyyucca/SIMA-V2-AKP` dan secret `PORTAINER_WEBHOOK_URL` (webhook stack, alamat localhost). Tanpa itu, tekan *Pull and redeploy* di Portainer.

## Cutover dari SIMA

Hostname agent SIMA (`sima.lameruru.web.id`) sebaiknya dipertahankan: pada hari cutover arahkan proxy domain itu ke port aplikasi ini (`ASSET_URL=https://sima.lameruru.web.id`). Detail: `docs/rencana-migrasi-sima.md`.
