# Runbook cutover: SIMA → IT Asset & ITERA

Ikuti berurutan. Setiap langkah punya **gerbang**: jangan lanjut kalau belum hijau. Perintah dijalankan di kontainer `asset`
(Portainer > stack > asset > Console, atau `docker exec -it <kontainer> sh`), kecuali dikatakan lain.

Peran: **Pelaksana** (Tim IT), **Pengamat** (orang kedua yang memeriksa gerbang).

---

## 0. Cutover di server `lm-srv-01` (hostname SAMA: sima.lameruru.web.id → SIMA2)

Kondisi per 2026-11-19 (sudah diverifikasi di server):

| Item | Status |
|---|---|
| SIMA1 = stack `sima_akp` (web 8105, DB `sima_akp-db-1`), SIMA2 = stack `sima-v2-akp` (web 8116, URL uji `sima-v2.lameruru.web.id`) | berjalan |
| Data SIMA1 sudah diimpor ke SIMA2 (725 aset, 128 token agent, ...), rekonsiliasi bersih | selesai; diperbarui dengan `sync-from-sima1.ps1` |
| Versi paket agent: SIMA1 = SIMA2 = **v9**; tanda tangan v9 VALID terhadap kunci publik yang tertanam di agent | terverifikasi |
| Kunci privat penanda tangan (RSA-3072) berpasangan dengan kunci publik agent | terverifikasi; file ada di PC admin, **bukan** di repo/server |
| QR label lama (`/hardware/<id SIMA>`, `/hardware/bytag/<tag>`) dialihkan ke aset yang sama | terpasang + tes |
| Domain diarahkan lewat **Cloudflare Tunnel** (token, dikelola di dashboard Cloudflare) | **dilakukan manual oleh pemilik akun Cloudflare** |

### Hari-H (urut)

1. **Latihan terakhir** (kapan saja sebelumnya): `powershell -ExecutionPolicy Bypass -File C:\akp-backup\sync-from-sima1.ps1 -DryRun`, lalu tanpa `-DryRun`. Gerbang: "Rekonsiliasi BERSIH".
2. **Bekukan SIMA1** (baca-saja): `docker exec sima_akp-sima-1 php artisan down --retry=60`. Agent akan mencoba lagi sendiri (tidak error permanen).
3. **Sinkron akhir**: `powershell -ExecutionPolicy Bypass -File C:\akp-backup\sync-from-sima1.ps1`. Gerbang: "Rekonsiliasi BERSIH" dan exit code 0. Jika selisih: STOP, `php artisan up` di SIMA1, selidiki.
4. **Ubah env stack SIMA2** di Portainer: `ASSET_URL=https://sima.lameruru.web.id`, Pull and redeploy. (`APP_URL` dipakai untuk tautan di BAST/notifikasi.)
5. **Cloudflare Zero Trust > Networks > Tunnels > (tunnel server) > Public hostname**: ubah `sima.lameruru.web.id` dari `http://localhost:8105` menjadi `http://localhost:8116`. (Pertahankan `sima-v2.lameruru.web.id` sampai selesai uji.)
6. **Portal** (Admin > Modul > asset): ubah URL modul menjadi `https://sima.lameruru.web.id` (kode `asset` dan rahasia tetap). Modul lama `sima`, jika ada, dinonaktifkan.
7. **HRIS**: `SIMA_URL=https://sima.lameruru.web.id`, `SIMA_API_TOKEN` = nilai di SIMA2; redeploy HRIS. Di SIMA2: `HRIS_API_TOKEN` = token layanan HRIS (64 karakter), lalu `php artisan hris:sync-employees` (simulasi) dan `--apply`.
8. `php artisan sima:smoke` di kontainer `asset` (tanpa `--skip-network`), lalu uji manual (login Portal, buka `/hardware/<id lama>`, kartu Agent pada satu aset, Monitor Agent).
9. **Pantau agent**: menu Monitor Agent; ≥95% lapor dalam 30 menit. Agent tidak perlu disentuh: URL, token, dan kunci update sama.

### Rollback (dalam 48 jam)
Cloudflare: kembalikan `sima.lameruru.web.id` ke `http://localhost:8105`; di SIMA1 `php artisan up`; HRIS `SIMA_URL` kembali. Token agent di SIMA1 tidak pernah diubah. Perubahan yang terjadi di SIMA2 selama itu tidak kembali otomatis (ekspor lalu input ulang).

### Kunci penanda tangan update agent
- Lokasi: `~/develop/sima/agent-signing-private.xml` (Mac pengembang). Standar rancangan: `%USERPROFILE%\.sima-agent-keys\agent-signing-private.xml` di PC admin IT. **Jangan** di repo, server, atau chat; `.gitignore` memblokirnya.
- Merilis versi baru ke agent (tanpa mengubah kunci): naikkan `$AgentVersion` di `agent-client/agent-update.ps1`, jalankan `agent-installer\tools\publish-agent-update.ps1 -Version 10 -Notes "..."` (kunci privat dibaca dari PC admin), lalu `git add agent-updates && git commit && git push`; CI membangun image dan Anda tekan Pull and redeploy. Server hanya menyajikan berkas; keaslian diperiksa agent dengan kunci publiknya.
- **Jangan ganti pasangan kunci** saat cutover: agent hanya menerima paket yang ditandatangani kunci yang tertanam di agent.

### Yang berubah bagi pengguna
- Tag aset memakai format baru (`AKP-IT-NB-0001`). Tag lama tetap berlaku sebagai alias: pindai barcode 1D lama atau cari tag lama di sistem tetap menemukan aset. QR lama tetap membuka aset yang sama. Label lama tidak perlu dicetak ulang.
- Login lewat Portal (SSO); pengguna butuh akses modul `asset` di Portal.

---

## A. Persiapan (H-14 sampai H-7)

| # | Langkah | Gerbang |
|---|---|---|
| A1 | Stack Portainer `asset` jalan di server (lihat `deploy/portainer/README.md`), modul `asset` terdaftar di Portal, `SSO_ENABLED=true`, `SSO_ALLOW_LOCAL_LOGIN=true`. | `php artisan sima:smoke` tanpa **GAGAL** |
| A2 | Hapus akun demo (`*@inventaris.test`) di produksi. Admin pertama masuk lewat Portal sebagai super admin. | smoke: "Tidak ada akun demo" = OK |
| A3 | Isi `HRIS_API_URL`, `HRIS_API_TOKEN`; jalankan `php artisan hris:sync-employees` (simulasi) lalu `--apply`. | karyawan aktif HRIS terbaca; daftar "aktif di sini tapi tak ada di HRIS" ditinjau |
| A4 | Ambil **dump database SIMA** (`mysqldump --single-transaction sima > sima.sql`) + salin folder berkas (`public/uploads`, `storage/private_uploads`). Restore dump ke MariaDB **terpisah** (`sima_legacy`), BUKAN database produksi SIMA. Isi `LEGACY_DB_*` dan `SIMA_FILES_PATH`. | `php artisan sima:import --dry-run` selesai tanpa error |
| A5 | Tinjau laporan dry-run (`storage/app/private/sima-import/<waktu>-dry/`): `laporan.json` (peringatan, status tak dikenal) dan `pengguna-tanpa-nik.csv`. Putuskan: pemetaan status (`config/sima_import.php > status_overrides`), awalan kategori, serial duplikat, pengguna tanpa NIK. | semua "status tak dikenal" sudah dipetakan; keputusan tercatat |
| A6 | **Latihan 1**: `sima:import --files=<folder berkas>` ke database staging, lalu `sima:reconcile`. | rekonsiliasi **BERSIH** (exit 0) |
| A7 | Sampel 30 aset dicek Tim IT di UI (foto, BAST, riwayat, pemegang) vs SIMA. | 30/30 sesuai |
| A8 | **Latihan 2 dan 3** pada dump terbaru (ulang dari database kosong); hasil rekonsiliasi identik dengan latihan 1. | 3x bersih |
| A9 | **Pilot agent**: 10 laptop dipilih, token disalin dari impor; arahkan hostname server agent ke aplikasi baru HANYA untuk uji (atau pakai `simaUrl` lain di `config.json` 10 laptop itu). Cek heartbeat, perintah jarak jauh, update, chat. | 10/10 lapor dalam 15 menit, perintah uji sukses |
| A10 | Pengumuman ke karyawan (jadwal cutover, login lewat Portal). | terkirim |

Catatan hostname agent: **pertahankan** hostname lama (`sima.lameruru.web.id`). Ini yang membuat sekitar 300 agent tidak perlu disentuh.
Jika terpaksa berubah: rilis `agent-N.zip` baru (ditandatangani kunci offline yang sama) di SIMA lama berisi `simaUrl` baru, tunggu semua agent update, baru matikan SIMA.

---

## B. Hari-H (akhir pekan)

| # | Langkah | Gerbang |
|---|---|---|
| B1 | **T-60 menit**: backup database SIMA produksi + database `asset`. Catat versi image (`ASSET_TAG`). | file backup ada dan bisa dibuka |
| B2 | **T-30**: SIMA masuk mode baca-saja: `php artisan down --retry=60` di SIMA (agent akan mencoba lagi sendiri). Tidak ada lagi perubahan data di SIMA. | halaman SIMA menampilkan maintenance |
| B3 | Dump final SIMA → restore ke `sima_legacy` (timpa). | restore selesai tanpa error |
| B4 | `php artisan sima:import --files=<folder berkas> --dry-run`, lalu tanpa `--dry-run`. | "IMPOR NYATA" tanpa GAGAL |
| B5 | `php artisan sima:reconcile` | **BERSIH**. Jika ada selisih: STOP, lihat bagian D (rollback) bila tidak terjelaskan dalam 30 menit |
| B6 | `php artisan hris:sync-employees --apply` | selesai |
| B7 | **Alihkan lalu lintas**: arahkan proxy `sima.lameruru.web.id` (atau ubah `ASSET_URL`) ke port aplikasi baru (8116). Di HRIS ubah `SIMA_URL` ke aplikasi baru, token `SIMA_API_TOKEN` sama. | `curl https://<host>/up` = 200 |
| B8 | `php artisan sima:smoke` | tanpa **GAGAL** |
| B9 | **Uji asap manual** (Pengamat mencentang): login Portal sebagai Admin, Staff IT, User; buat request ITERA; serah terima + unduh BAST PDF; pindai label lama (`/s/<tag lama>` harus menemukan aset lewat alias); halaman aset menampilkan kartu Agent; HRIS offboarding menampilkan aset karyawan. | 100% tercentang |
| B10 | Pantau **Monitor Agent** (menu Inventaris). | ≥ 95% agent aktif lapor dalam 30 menit; sisanya laptop mati/offline |

Jika B9/B10 gagal dan tidak teratasi dalam 30 menit: **rollback** (bagian D).

---

## C. Setelah cutover

| Kapan | Langkah |
|---|---|
| H+1 hari | Cek log kontainer (`LOG_LEVEL=warning` ke stderr), antrean (`failed_jobs`), kegagalan SSO, agent offline massal. |
| H+2 hari | Gerbang rollback ditutup bila tidak ada masalah. Setel `SSO_ALLOW_LOCAL_LOGIN=false`. Kosongkan `LEGACY_DB_*`. |
| H+7 hari | Lengkapi NIK pengguna di `pengguna-tanpa-nik.csv`; jalankan `hris:sync-employees` lagi. Tinjau karyawan resign yang masih memegang aset (laporan sinkron). |
| H+90 hari | Arsipkan SIMA: dump final + folder berkas disimpan; matikan kontainernya. Cabut `SIMA_API_TOKEN` lama. |

---

## D. Rollback (dalam 48 jam)

1. Arahkan proxy `sima.lameruru.web.id` kembali ke SIMA; di SIMA `php artisan up`.
2. HRIS: kembalikan `SIMA_URL`.
3. Agent otomatis lapor ke SIMA lagi (token lama tidak pernah diubah di SIMA).
4. **Perubahan yang terjadi di aplikasi baru selama itu TIDAK kembali otomatis.** Ekspor dari aplikasi baru (Laporan > Aset / Riwayat) dan masukkan ulang manual ke SIMA. Karena itu selama masa 48 jam: hindari perubahan data selain yang perlu.
5. Catat penyebab; perbaiki; ulangi latihan sebelum cutover berikutnya.

---

## E. Referensi perintah

| Perintah | Fungsi |
|---|---|
| `php artisan sima:import --dry-run` | Simulasi penuh, semua perubahan dibatalkan; hanya laporan |
| `php artisan sima:import [--only=assets,assignments] [--files=PATH]` | Impor nyata (idempoten; boleh diulang). `--only` untuk langkah tertentu |
| `php artisan sima:reconcile` | Bandingkan SIMA vs baru; exit 1 bila ada selisih |
| `php artisan sima:smoke [--skip-network]` | Uji asap pasca-deploy |
| `php artisan hris:sync-employees [--apply]` | Sinkron karyawan dari HRIS |
| `php artisan agent:mark-stale-offline` | Tandai agent yang berhenti lapor (terjadwal tiap 5 menit) |

Aturan impor yang bergantung data produksi ada di `config/sima_import.php`. Peta id lama→baru tersimpan di tabel `legacy_map` (jejak audit).

Yang **tidak** dibawa dan alasannya: `action_logs` selain checkout/checkin (arsipkan dump SIMA), riwayat perintah agent selesai, `req_histories` (tiket lama tetap di arsip SIMA; ITERA mulai dari nol), kategori lisensi (tabel lisensi tidak memakai kategori), pengguna tanpa NIK yang tidak dirujuk data apa pun.
