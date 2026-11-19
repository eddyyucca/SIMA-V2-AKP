# Rencana Migrasi SIMA-AKP → Asset Management (ITERA) + Integrasi HRIS & Portal SSO

Status (2026-11-19): **Semua fase SUDAH DIBANGUN dan teruji terhadap data tiruan.** Yang tersisa hanya yang butuh produksi: dump database SIMA asli, latihan di data nyata, pilot agent, dan hari-H. Langkah operasional lengkap: `docs/runbook-cutover-sima.md`.

| Fase | Status | Bukti |
|---|---|---|
| 0 Persiapan | Alat siap: `sima:import --dry-run` (laporan profil data), koneksi `legacy` | Qa61 |
| 1 HRIS + SSO | Selesai | Qa60 (18 tes) + uji nyata dengan Portal lokal |
| 2 Impor + rekonsiliasi | Selesai: `sima:import`, `sima:reconcile`, fixture legacy dari skema Snipe-IT asli | Qa61 (8 tes), impor 3x idempoten |
| 3 Agent | Selesai: API kompatibel (termasuk bentuk error HTTP 200 Snipe-IT), UI Filament, token lama tetap berlaku | tests/Agent (67 tes, 59 dari SIMA) |
| 4 Cutover | Runbook + `sima:smoke` | Qa62 |
| 5 Rollback | Prosedur di runbook | - |

Batasan yang jujur: semua tes memakai **data tiruan** yang meniru kasus sulit (serial duplikat, status tak dikenal, pengguna tanpa NIK, riwayat tak lengkap). Data produksi bisa memunculkan kasus baru; itulah gunanya latihan 3x dan laporan dry-run sebelum hari-H.

### Status Fase 1 (sudah dikerjakan)
- Paket `akp/portal-sso` dipasang (`packages/portal-sso`, batas versi dilonggarkan ke Laravel 13). Modul `asset` didaftarkan di Portal lokal; "Tes koneksi" dan "Deteksi level" (admin / staff / user) lulus; alur token end-to-end terbukti.
- **Koreksi rencana:** HRIS dibaca lewat **REST** (`GET {HRIS}/api/integrations/employees`, token layanan) seperti Portal, **bukan** koneksi database `hris`. Env: `HRIS_API_URL`, `HRIS_API_TOKEN`.
- `SSO_ENABLED` (bawaan `false`) menyalakan login Portal; login NIK lokal tetap ada sebagai akun darurat (`/admin/login?lokal=1`, `SSO_ALLOW_LOCAL_LOGIN`) sampai serah-terima selesai. Sesi lokal ikut umur sesi Portal (480 menit).
- `php artisan hris:sync-employees [--apply]` (terjadwal 02:00 bila HRIS dikonfigurasi): tambah / perbarui / tandai resign; cocokkan NIK alias; tidak pernah menghapus; melaporkan karyawan aktif yang tak ada di HRIS dan resign yang masih memegang aset.
- `GET /api/integrations/employees/{nik}/assets` (bearer `SIMA_API_TOKEN`): kontrak sama dengan SIMA lama, jadi HRIS cukup mengganti `SIMA_URL`.
- Deploy: `Dockerfile`, `docker/`, `.github/workflows/build-push.yml` (tes PostgreSQL, image ke ghcr.io, webhook Portainer), `deploy/portainer/docker-compose.git.yml` (web + queue + scheduler + PostgreSQL). Lihat `deploy/portainer/README.md`.


## 0. Ringkasan keputusan

| Hal | Keputusan usulan |
|---|---|
| Sumber data | `Sima_AKP` (Snipe-IT / Laravel 12, MariaDB 11). **Butuh dump database SIMA produksi** (tidak ada DB hidup di mesin ini; `data lama/htdocs/127_0_0_1.sql` adalah data lama, bukan SIMA). |
| Target | `asset-management` (Laravel 13, Filament 5, **PostgreSQL 16**, sudah dipindah dari MariaDB per 2026-11-19; 686 tes lulus). Skema target **tidak diubah demi SIMA**; data SIMA yang dipetakan ke skema target. Pengecualian: tabel agent (baru). |
| Cara migrasi | Command artisan `sima:import` bertahap, **idempoten** (boleh diulang), punya `--dry-run`, tabel pemetaan `legacy_map` (id lama → id baru), dan laporan rekonsiliasi. |
| Karyawan | **HRIS = sumber kebenaran**, kunci `employees.employee_no` ↔ `employees.nik` (target). Karyawan SIMA dicocokkan ke HRIS lewat NIK (+ `employee_nik_aliases`). |
| Login | **Portal AKPSatu SSO** (JWT HS256, `/sso/callback?token=`). Login NIK+password lokal dimatikan setelah cutover (kecuali akun darurat). |
| Agent | Endpoint agent SIMA dipindah **dengan kontrak HTTP yang sama** supaya ±300 agent terpasang tetap jalan tanpa install ulang. |
| Cutover | Big-bang di akhir pekan, SIMA jadi **read-only** dulu, rollback = nyalakan lagi SIMA. |

## 1. Hasil pemetaan (yang sudah saya baca)

### 1.1 SIMA (sumber)
- Snipe-IT yang dimodifikasi. Custom AKP: `bast_histories`, `req_histories`, `consumable_transactions`, `component_transactions`, `assets.bast_number`, `users.hris_section`, command `hris:sync-users`, `routes/integrations.php` (HRIS membaca aset karyawan lewat `SIMA_API_TOKEN`).
- **Agent** (fitur utama yang harus ikut pindah):
  - Server: `routes/agent.php` → `api/agent/v1/{heartbeat, commands/{id}/result, update, update/package, version}` dan `api/helper/v1/{poll, announcements/*, chat/messages}`.
  - Tabel: `asset_agent_tokens` (token di-hash + `lookup_hash`), `asset_heartbeats` (1 baris terkini/aset), `asset_spec_changes`, `asset_agent_commands`, `agent_announcements` (+targets, deliveries), `agent_chat_sessions/messages`, `agent_rollout_snapshots`, `asset_helper_tokens`; kolom `assets.agent_status`, `agent_last_seen`; command `agent:mark-stale-offline`, `AgentRolloutSnapshotCommand`.
  - Klien: `agent-client/*.ps1`, `agent-installer/` (Inno Setup, helper-app, status-app), `agent-updates/` (paket `agent-8/9.zip` **ditandatangani offline**, manifest + signature).
- Kunci agent: dicocokkan ke aset lewat token per-aset; serial dibandingkan dengan `assets.serial` (badge Mismatch).

### 1.2 Target (asset-management)
- Tabel inti: `categories, departments, locations, vendors, employees(nik), assets(asset_tag AKP-IT-xx-0001, serial_number, spec_data, rental_*, depreciation), accessories, consumables, components, licenses, license_seats, asset_assignments(+BAST), stock_movements, repairs, disposals, stock_opnames, ip_addresses, rental_contracts, asset_models/brands, arrival_plans, ITRequest` + ITERA.
- User: Spatie roles `Admin / Staff IT / User`; login by NIK; `employees.user_id`. **Belum ada** SSO, HRIS, maupun tabel agent.

### 1.3 HRIS & Portal
- HRIS (`HRIS_AKP`, Laravel modular, PostgreSQL `akp_hris`): `employees.employee_no`, departments/divisions/sections/positions, `employee_nik_aliases`, status resign (modul Offboarding).
- Portal (`Portal-AKPSatu`, PostgreSQL `akp_portal`): token SSO JWT HS256, umur 60 dtk, `aud`=kode modul, klaim `usr, emp, name, email, role, level, admin, pos, dept, phone`; modul didaftarkan di **Admin > Modul** (dapat `sso_secret`, level `admin`/`user`); ada tombol *Tes koneksi*. HRIS sudah jadi consumer (`/sso/callback`, `config/sso.php`) → **pola yang ditiru**.
- Portal membaca HRIS lewat koneksi DB read-only `hris`.

## 2. Fase & urutan kerja

Setiap fase punya **gerbang lulus**; jangan lanjut kalau gerbang belum hijau.

### Fase 0 — Persiapan & pengaman (tidak mengubah apa pun)
1. Minta/ambil **dump SIMA produksi** + folder `storage/private_uploads`, `public/uploads` (foto aset, EULA/BAST PDF, lampiran).
2. Restore dump ke DB staging terpisah `sima_legacy` (koneksi `legacy` read-only di target).
3. Backup target & HRIS/Portal. Buat staging target: PostgreSQL (database `akp_asset`), koneksi kedua `legacy` (MariaDB, read-only) hanya untuk importer.
4. Inventarisasi: jumlah baris tiap tabel SIMA, status aset, custom field, aset tanpa serial/tag, duplikat serial, user tanpa NIK.
   - **Gerbang:** laporan profil data selesai & disetujui (lihat §3 untuk keputusan terbuka).

### Fase 1 — Fondasi integrasi (HRIS + Portal SSO) di target
1. Koneksi DB `hris` read-only di `config/database.php` (`HRIS_DB_*`), service `EmployeeDirectory` (cari by NIK + alias, ambil nama/jabatan/dept/section/status).
2. **SSO consumer**: route `/sso/callback`, verifikasi JWT (aud=`asset`, secret `PORTAL_SSO_SECRET`, `exp/nbf`, **jti sekali pakai** via cache), cari/buat `User` by `emp` (NIK), pasang role dari klaim `level/role` (map di `config/sso.php`: `admin→Admin`, level IT→`Staff IT`, `user→User`), `is_active` ikut Portal, sesi maks 8 jam lalu kembali ke Portal.
3. Redirect login Filament `/admin/login` & `/requisition` ke `Portal /login?app=asset`. Sisakan `/admin/login-lokal` hanya untuk akun darurat (dibatasi, non-karyawan).
4. Daftarkan modul **Asset** di Portal (Admin > Modul): kode, URL, ikon, level `admin`/`staff`/`user`, salin secret ke `.env`.
5. **Sinkron karyawan** `hris:sync-employees` (terjadwal harian): HRIS → `employees` target (nik, nama, email, jabatan, department, status resign → `resigned`; hook resign yang sudah ada menonaktifkan user). `departments` dipetakan dari HRIS (buat bila belum ada). Resign dengan aset masih dipegang → **notifikasi ke Tim IT** (jangan hapus).
6. Endpoint balik untuk HRIS: `GET /api/integrations/employees/{nik}/assets` (token layanan) — meniru yang sekarang dipakai HRIS ke SIMA, supaya tampilan HRIS tidak putus.
   - **Gerbang:** login lewat Portal sukses untuk Admin/Staff/User; token kedaluwarsa & dipakai-ulang ditolak; karyawan resign tidak bisa masuk; tes otomatis ditambahkan.

### Fase 2 — Impor data SIMA (staging dulu, tiga kali latihan)
Urutan (mengikuti ketergantungan FK), tiap langkah mencatat `legacy_map(entity, legacy_id, new_id)`:

| # | Entitas SIMA | → Target | Catatan pemetaan |
|---|---|---|---|
| 1 | companies/departments | `departments` | Dedup nama (case-insens.). FMCS company diabaikan kecuali dipakai. |
| 2 | locations | `locations` | Hirarki parent diratakan jadi "Induk > Anak". |
| 3 | suppliers | `vendors` | |
| 4 | categories | `categories` | Kategori SIMA→kategori target; tentukan penyusutan (default per kategori). Daftar mapping disetujui manual. |
| 5 | manufacturers + models | `brands` + `asset_models` | |
| 6 | status_labels | `assets.status` | Deployable→`available`/`deployed` (tergantung checkout), Pending/Repair→`repair`, Archived/Lost→`lost`, Disposed→`disposed`; label lain → **daftar manual**. |
| 7 | users | `employees` (+`users`) | Cocokkan ke HRIS by NIK/username; **yang tidak cocok → file `unmatched_users.csv` untuk diputuskan manual**, jangan dibuat otomatis. User tanpa HRIS (admin teknis) → akun non-karyawan. |
| 8 | assets | `assets` | `asset_tag` **dibangun ulang ke format AKP-IT-<kode>-0001** dan tag lama disimpan di `asset_tag_aliases` (tabel sudah ada) supaya QR/label lama tetap terbaca. `serial`→`serial_number` (duplikat → laporan). custom fields → `spec_data` (cpu/ram/hdd/vga/motherboard/os/name) atau `notes`. `bast_number` lama → `asset_assignments.bast_number`. Foto/lampiran disalin & path ditulis ulang. |
| 9 | action_logs checkout/checkin | `asset_assignments` | Direkonstruksi per aset berurutan waktu (`checkout`/`checkin`/`transfer`); `bast_histories` → `bast_pdf_path`/`asset_bast_files`. Status aset akhir harus konsisten dengan assignment terakhir. |
| 10 | accessories, consumables, components | idem | Saldo awal = **stok akhir SIMA** sebagai satu `stock_movements` tipe penyesuaian (tanggal cutover); `consumable_transactions`/`component_transactions` → `stock_movements` historis bila kolomnya cukup, kalau tidak diarsipkan CSV. |
| 11 | licenses + seats | `licenses`, `license_seats` | |
| 12 | maintenances | `repairs` | |
| 13 | req_histories | `ITRequest` (arsip) | Hanya bila perlu; usulan: impor read-only dengan nomor tiket lama, tanpa memicu notifikasi. |
| 14 | action_logs lain | arsip CSV/`activity_log` | Tidak diimpor penuh (volume); simpan dump legacy 1 tahun. |

Catatan PostgreSQL: `LIKE` sudah dibuat case-insensitive lewat grammar khusus; id dari sumber MariaDB yang dimasukkan manual harus diikuti `setval` sequence; kolom JSON memakai `jsonb`; unique index peka huruf besar/kecil (validasi aplikasi menutupnya).

Aturan teknis impor: transaksi DB per entitas, `--dry-run`, `--only=`, `--since=`, batch + chunk, matikan notifikasi/Telegram/event Audited saat impor, hitung ulang penyusutan setelah selesai (`DepreciationService`).

**Rekonsiliasi otomatis** (command `sima:reconcile`, output tabel + CSV):
- Jumlah baris per entitas (sumber vs target, selisih harus 0 atau terjelaskan).
- Per status: total aset tiap status sama.
- Per karyawan: jumlah aset dipegang sama (SIMA vs target) — daftar selisih.
- Total nilai pembelian, stok per accessory/consumable/komponen.
- Setiap `serial` & `asset_tag` unik, tidak ada aset yatim (tanpa kategori), tidak ada assignment tanpa BAST.
- Sampel 30 aset dicek manual oleh Tim IT (foto, BAST, riwayat).
   - **Gerbang:** 3 kali latihan penuh di staging, rekonsiliasi nol selisih tak terjelaskan, UAT Tim IT lulus.

### Fase 3 — Migrasi Agent
**Prinsip:** agent terpasang tidak boleh perlu diinstal ulang.
1. **Kontrak API tetap** (path, header token, JSON heartbeat/commands/update/version/helper). Pindahkan `routes/agent.php`, controller (`AssetAgentController`, `AgentHelperController`), middleware (`AssetAgentToken`, `AssetHelperTokenAuth`), throttle (`agent-ip`, `agent`, `agent-helper`).
2. Migrasi tabel agent dengan **tipe kolom disesuaikan** (`asset_id` jadi `unsignedBigInteger` karena `assets.id` target bigint; sebelumnya int karena Snipe-IT). Nama tabel & kolom dipertahankan agar copy data langsung.
3. **Token**: salin `asset_agent_tokens` **apa adanya** (`token_hash`, `lookup_hash`) dengan `asset_id` dipetakan lewat `legacy_map`. Token plaintext tidak ada & tidak diminta; jangan regenerasi.
4. Salin `asset_heartbeats` (kondisi terkini), `asset_spec_changes`, `asset_agent_commands` yang masih pending, announcements/chat (bila masih relevan), `agent_rollout_snapshots`.
5. UI di Filament: kolom *Agent Status* di daftar aset, panel **Device Info (Agent)** di detail aset, tombol *Create/Revoke token*, halaman Monitor, Pengumuman, Chat, Perintah jarak jauh. Policy: hanya Admin/Staff IT.
6. Jadwal: `agent:mark-stale-offline` tiap 5 menit, snapshot rollout; cabut token otomatis saat aset di-checkin/disposal (perilaku SIMA, diuji).
7. **Alamat server**: pertahankan hostname lama (DNS/reverse proxy diarahkan ke aplikasi baru) — **ini cara teraman**. Jika hostname harus berubah: rilis `agent-10.zip` (ditandatangani dengan kunci offline yang sama) di SIMA lama berisi `simaUrl` baru, tunggu seluruh agent update sebelum mematikan SIMA.
8. `agent-updates/` (manifest, zip, signature) dan `agent-client`/`agent-installer` dipindahkan ke repo target **tanpa dimodifikasi**; kunci publik penanda tangan tetap sama.
9. Pencocokan serial agent ↔ `assets.serial_number` (badge Mismatch) dipertahankan.
   - **Gerbang:** (a) uji dengan 1 laptop nyata di staging: heartbeat, perintah jarak jauh, update, chat; (b) pilot 10 laptop di produksi sebelum cutover; (c) setelah cutover ≥95% agent lapor dalam 30 menit.

### Fase 4 — Cutover (akhir pekan)
1. T-7 hari: pengumuman; freeze perubahan master (kategori/lokasi).
2. T-1 jam: SIMA read-only (maintenance mode untuk non-GET), dump final.
3. Jalankan `sima:import --final` (inkremental dari dump latihan terakhir), `sima:reconcile`.
4. Arahkan DNS/proxy agent & user ke aplikasi baru; modul Asset aktif di Portal; matikan login lokal.
5. Smoke test (checklist): login SSO ×3 peran, buat request ITERA, serah terima + BAST PDF, scan label lama (alias tag), heartbeat agent, HRIS menampilkan aset karyawan.
6. Pantau 48 jam: log error, antrian, kegagalan SSO, agent offline massal.

### Fase 5 — Rollback & penutupan
- **Rollback** (dalam 48 jam): DNS kembali ke SIMA, buka read-only. Perubahan yang terjadi di target selama itu **tidak** otomatis kembali → ekspor ke CSV dan input ulang manual; karena itu masa 48 jam dijaga dengan perubahan minimal.
- SIMA dipertahankan read-only 3 bulan lalu diarsipkan (dump + uploads).
- Hapus akun/ token integrasi lama (`SIMA_API_TOKEN`) setelah HRIS memakai endpoint baru.

## 3. Hal yang harus diputuskan/dipenuhi sebelum mulai

1. **Dump database SIMA produksi + folder uploads** (blocker Fase 0). Tanpa ini pemetaan status/custom field hanya perkiraan.
2. Apakah **hostname server agent** boleh dipertahankan (sangat disarankan)?
3. Apakah **kunci penanda tangan offline** paket agent masih ada? (Tanpa itu, tidak bisa merilis update baru.)
4. Format `asset_tag` lama SIMA vs `AKP-IT-NB-0001`: tag lama dibuat alias (usulan) atau dipertahankan sebagai tag utama?
5. Riwayat `action_logs`: cukup arsip CSV (usulan) atau harus tampil di aplikasi baru?
6. Mapping level Portal → role (`Admin / Staff IT / User`): siapa saja yang jadi Admin & Staff IT.
7. Server target produksi: PostgreSQL 16 (sama dengan Portal & HRIS, bisa satu server berbeda database). Koneksi `hris` memakai `pdo_pgsql`; importer butuh `pdo_mysql` untuk membaca dump Sima (MariaDB).
8. Boleh menambah dependensi `firebase/php-jwt` ke target (dipakai Portal/HRIS).

## 4. Risiko & mitigasi

| Risiko | Mitigasi |
|---|---|
| Serial/tag duplikat membuat impor gagal | Pra-cek Fase 0; duplikat dilaporkan dan diputuskan manual, bukan dihapus otomatis. |
| Riwayat checkout tidak konsisten dengan status akhir | Bangun assignment berurutan, bandingkan dengan `assigned_to` SIMA; selisih masuk laporan. |
| Agent terputus setelah cutover | Hostname tetap, token disalin apa adanya, pilot 10 laptop, SIMA tetap siaga. |
| Karyawan SIMA tak ada di HRIS | `unmatched_users.csv` + keputusan manual; akun non-karyawan hanya untuk admin teknis. |
| Notifikasi massal saat impor | Matikan event/notifier selama impor. |
| SSO gagal = tidak ada yang bisa masuk | Akun darurat lokal terbatas + prosedur rollback + uji sebelum cutover. |
| Kehilangan lampiran/BAST | Salin file lalu verifikasi checksum & jumlah; path ditulis ulang di `legacy_map`. |

## 5. Pengujian

- Unit/feature test: verifikasi JWT (valid, kedaluwarsa, aud salah, jti ulang), sinkron HRIS, setiap importer (fixture kecil dari dump), kontrak API agent (snapshot respons SIMA vs target).
- Dry-run harian di staging selama latihan; rekonsiliasi harus identik antar latihan.
- UAT Tim IT dengan daftar centang (aset, BAST, stok, request, agent).

## 6. Estimasi kasar & urutan eksekusi yang diusulkan

| Fase | Perkiraan |
|---|---|
| 0 Persiapan & profil data | 2–3 hari (menunggu dump) |
| 1 HRIS + Portal SSO | 3–4 hari |
| 2 Importer + rekonsiliasi + 3 latihan | 5–7 hari |
| 3 Agent | 3–4 hari + pilot 1 minggu |
| 4–5 Cutover & pemantauan | 1 hari + 2 hari pantau |

Langkah berikutnya begitu rencana ini disetujui: **Fase 0** (butuh dump SIMA) dan, paralel, **Fase 1** (tidak bergantung pada dump).
