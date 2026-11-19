# Runbook cutover: SIMA → IT Asset & ITERA

Ikuti berurutan. Setiap langkah punya **gerbang**: jangan lanjut kalau belum hijau. Perintah dijalankan di kontainer `asset`
(Portainer > stack > asset > Console, atau `docker exec -it <kontainer> sh`), kecuali dikatakan lain.

Peran: **Pelaksana** (Tim IT), **Pengamat** (orang kedua yang memeriksa gerbang).

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
