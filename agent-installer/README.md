# SIMA Asset Agent — Installer (Inno Setup)

Installer resmi `.exe` untuk SIMA Asset Agent, dibungkus dari skrip di
[`../agent-client/`](../agent-client/) (lihat dulu README di sana untuk cara kerja agen dan cara trial
manual tanpa installer). Lihat juga [`../docs/rencana-agent-monitoring-aset.md`](../docs/rencana-agent-monitoring-aset.md).

## Build

Butuh [Inno Setup 7](https://jrsoftware.org/isinfo.php) terpasang, dan `../apps/Net Employe/Setup.exe`
(installer Net Monitor for Employees Pro) harus ada secara lokal — **tidak ikut di-commit ke git**
(lihat `.gitignore`: berlisensi komersial dari EduIQ, bukan milik AKP untuk didistribusikan lewat repo).
Tanpa file itu, compile akan gagal di langkah `[Files]`.

**Lewat ISIDE (GUI):** buka `SIMA-Asset-Agent.iss`, lalu **Build > Compile** (atau `Ctrl+F9`).

**Lewat command line:**
```powershell
& "C:\Program Files\Inno Setup 7\ISCC.exe" SIMA-Asset-Agent.iss
```

Hasilnya: **satu** file, `dist\SIMA-Asset-Agent-Setup.exe` (±48 MB, Net Monitor ikut di dalamnya) — satu
klik untuk didistribusikan, tidak perlu menjaga dua file tetap berdampingan.

## ⚠️ Satu file, tapi dua PROSES terpisah saat dijalankan

`SIMA-Asset-Agent-Setup.exe` memasang **SIMA Asset Agent** (otomatis, inti dari installer ini) dan di
halaman Finish menawarkan tombol untuk **melanjutkan** ke installer **Net Monitor for Employees Pro**
(EduIQ.com). Net Monitor diekstrak ke folder permanen `{app}\redist\` (bukan folder temp instalasi —
percobaan awal pakai folder temp menyebabkan Windows menolak elevasi admin-nya sendiri:
`CreateProcess failed; code 740`) lalu dijalankan lewat `shellexec` sebagai **proses sendiri** (PID,
jendela, dialog UAC masing-masing) begitu diklik Finish — bukan bagian dari proses installer SIMA, dan
bukan proses tersembunyi. Dua program ini SANGAT berbeda sifatnya:

| | SIMA Asset Agent | Net Monitor for Employees Pro |
|---|---|---|
| Dipasang otomatis? | Ya | **Tidak** — kotak centang di halaman Finish, default **tidak tercentang** |
| Dibuat oleh | AKP (repo ini) | EduIQ.com (pihak ketiga, hanya dibundel) |
| Kemampuan | Status online/offline, info hardware, kepatuhan BitLocker/Defender/patch | Lihat layar real-time, screenshot, rekam layar, **keylogging**, ambil alih mouse/keyboard, blokir aplikasi/USB, shutdown jarak jauh |
| Transparansi | Log lokal bisa dibuka siapa saja, Scheduled Task bernama jelas | Tergantung bagaimana IT mengonfigurasi & memberi tahu karyawan — **ini tanggung jawab IT/HR, bukan installer ini** |

Kalau admin mencentang kotak di halaman Finish, wizard installer EduIQ miliknya **sendiri** yang terbuka
(bukan silent, bukan tersembunyi) — admin mengisi wizard itu secara terpisah, dengan lisensi/konfigurasi
Net Monitor sendiri. [`info-before.txt`](info-before.txt) menjelaskan kemampuan penuh program ini ke
siapa pun yang menjalankan installer, SEBELUM kotak centang itu muncul.

**Sebelum memasang Net Monitor for Employees Pro ke laptop karyawan mana pun**: pastikan ada kebijakan
perusahaan yang mengizinkan (pemantauan keystroke/layar diatur hukum ketenagakerjaan di banyak tempat) dan
karyawan sudah diberi tahu. Jangan andalkan installer ini sebagai pengganti proses consent itu.

## Apa yang dilakukan installer ini

1. **Wajib Administrator.** `PrivilegesRequired=admin` di `.iss` membuat Windows menampilkan dialog UAC
   sebelum installer sempat terbuka — kalau ditolak atau dijalankan tanpa hak admin, instalasi tidak
   berjalan sama sekali. Tidak ada opsi "lewati admin".
2. **Halaman penjelasan** ([`info-before.txt`](info-before.txt)) — wajib dibaca/di-scroll sebelum lanjut,
   menjelaskan apa yang dilakukan agent, data apa yang dikirim, dan data apa yang **tidak pernah** dikirim
   (tidak ada keylog/screenshot/riwayat browsing/isi file/lokasi presisi). Ini bukan halaman "terms &
   conditions" ala EULA — isinya sengaja singkat dan jujur, dan ditulis Indonesia.
3. **Halaman input**: alamat SIMA + token agent (dibuat dari halaman detail aset di SIMA, tombol
   **"Create token"**). Divalidasi tidak boleh kosong sebelum lanjut.
4. Menyalin `agent.ps1`, `install.ps1`, `uninstall.ps1` (persis dari `agent-client/`, bukan salinan
   terpisah yang bisa beda) ke `C:\ProgramData\SIMA\agent\`.
5. Menulis `config.json` dari jawaban di langkah 3.
6. Memanggil `install.ps1 -IntervalMinutes 10`, yang mendaftarkan Scheduled Task **"SIMA Asset Agent"** —
   nama jelas, terlihat normal di Task Scheduler, bukan proses tersembunyi (lihat `install.ps1` untuk
   detail: akun `SYSTEM`, `RunLevel Limited`, deskripsi task menyebutkan kontak IT).
7. Membuat dua pintasan di Start Menu (**Lihat Log Agent**, **Uninstall**) — supaya siapa pun yang pakai
   laptop ini bisa menemukan dan memeriksa agent ini kapan saja, konsisten dengan prinsip transparan.
8. Di halaman Finish, menawarkan kotak centang **(kosong secara bawaan)** untuk menjalankan installer
   Net Monitor for Employees Pro miliknya sendiri — lihat peringatan di atas sebelum mengaktifkan ini.

Uninstall (lewat Control Panel atau pintasan Start Menu) melepas Scheduled Task-nya lebih dulu
(`[UninstallRun]`), baru menghapus berkas termasuk `config.json` (berisi token) dan `agent.log`.

## Tampilan

Gambar wizard (`assets/wizard-image.bmp`, `assets/wizard-small.bmp`) dan ikon (`assets/icon.ico`)
dibuat dari logo resmi AKP (`public/AKP.png`), dengan palet yang sama dengan tema SIMA
(`public/akp/theme.css`: navy `#0e1c38`→`#203868`, aksen sky `#28a8e0`). Kalau logo atau warna berubah,
jalankan ulang:

```powershell
python tools\generate-assets.py
```

Butuh Pillow (`pip install pillow`) dan Python di laptop yang menjalankannya; hasilnya langsung ditimpa ke
`assets/`. Skrip ini hanya dijalankan sekali saat menyiapkan/memperbarui aset visual — bukan bagian dari
proses build/compile installer.

## ⚠️ Sebelum menguji installer di laptop yang sudah punya trial manual

Kalau laptop ini sebelumnya sudah dipasangi agent secara manual (lihat `agent-client/README.md`, Scheduled
Task bernama sama **"SIMA Asset Agent"**), menjalankan installer ini akan **menggantikan** task itu —
Scheduled Task lama akan dialihkan ke salinan baru di `C:\ProgramData\SIMA\agent\` dengan token baru yang
Anda masukkan di wizard. Token lama (dari trial manual) otomatis tercabut begitu Anda klik "Create token"
baru di SIMA (lihat `AssetAgentTokenController::store()`), jadi ini aman — cukup pastikan Anda memasukkan
token yang **baru dibuat**, bukan yang lama.

## Rollout ke banyak laptop (lihat Fase 3 di dokumen rencana)

Installer ini cocok untuk **trial per-laptop manual** (double-click, isi wizard). Untuk rollout ke ratusan
laptop sekaligus:
- Signing `.exe` ini dengan sertifikat code-signing perusahaan (supaya tidak diblokir SmartScreen/Defender
  tanpa perlu exclusion manual).
- Deploy lewat GPO/Intune dengan parameter silank (`/VERYSILENT /SUPPRESSMSGBOXES`), yang berarti wizard
  input URL/token tidak akan tampil — perlu pola enrollment otomatis (lihat Fase 3.2 di dokumen rencana)
  alih-alih mengandalkan wizard ini untuk seribu laptop satu-satu.

## Pembaruan otomatis agent (signed update)

Agent memeriksa versi terbaru di setiap laporan dan memasang update sendiri, **hanya** kalau paketnya
ditandatangani AKP IT. Kunci privat penanda tangan ada di PC admin IT (`%USERPROFILE%\.sima-agent-keys\`),
**bukan** di server atau repositori; agent hanya memuat kunci publiknya (`agent-client/agent-update.ps1`).
Server yang dibobol tidak bisa mendorong kode ke laptop.

**Merilis versi baru** (di PC admin IT yang memegang kunci):

1. Ubah kode agent, lalu naikkan `$AgentVersion = N` di `agent-client/agent-update.ps1`.
2. `powershell -ExecutionPolicy Bypass -File tools\publish-agent-update.ps1 -Version N -Notes "..." [-IncludeStatusApp]`
   (`-IncludeStatusApp` ikut menyertakan `SIMA-Agent-Status.exe`; bangun dulu lewat `status-app\build.ps1`).
3. `git add agent-updates && git commit && git push`. CI membangun image, stack di-redeploy, dan tiap laptop
   mengambil update pada laporan berikutnya (maks. sekitar 5 menit).

**Pengaman di sisi agent:** tanda tangan RSA-3072 diperiksa sebelum mengunduh; ukuran dan SHA-256 dicocokkan;
isi zip hanya `agent.ps1`, `agent-update.ps1`, `SIMA-Agent-Status.exe`; sintaks dan uji-jalan (`-SelfTest`)
sebelum dipasang; versi hanya boleh naik; cadangan versi lama di `backup\vN`; gagal berulang ditunda 60 menit.
Update yang gagal tidak pernah menghalangi laporan. Hasilnya tampil di aplikasi status (kartu "Pembaruan otomatis").

**Penting:** laptop yang masih memakai agent lama (sebelum fitur ini) harus memasang ulang sekali dengan installer
baru. Setelah itu update berikutnya otomatis. Jangan hilangkan atau bagikan kunci privat; kalau hilang, semua laptop
harus dipasang ulang dengan kunci publik baru (`tools\new-signing-key.ps1`).

## Halaman detail agent, benchmark, dan riwayat perubahan (agent v6)

- **Halaman khusus:** di halaman aset, tombol "Lihat detail lengkap & riwayat perubahan" membuka `/hardware/{id}/agent`
  (tab: Ringkasan, Prosesor & Memori, Penyimpanan, Jaringan & Baterai, Keamanan & Update, Software, Riwayat Perubahan).
  Hak aksesnya sama dengan melihat aset itu.
- **Benchmark:** sekali sehari (bersama inventaris) agent mengukur baca/tulis RAM (throughput memori, MB/s) dan baca/tulis
  berurutan disk drive sistem (256 MB, I/O langsung tanpa cache OS; berkas uji dihapus sesudahnya, dilewati bila ruang
  kosong < 2 GB). Angkanya **perkiraan**, bukan pengganti benchmark khusus.
- **Riwayat perubahan spesifikasi:** server membandingkan tiap laporan dengan laporan sebelumnya dan mencatat perubahan
  identitas (nama, serial, model), hardware (CPU, jumlah core, RAM total dan modul, kapasitas drive C:, disk fisik),
  sistem operasi (versi, build), dan software (dipasang/dihapus/diperbarui). Nilai dinamis (sisa disk, beban CPU, IP) tidak
  dicatat. Laporan pertama hanya menjadi acuan; bidang yang sebelumnya kosong atau daftar kosong bukan perubahan.
  Disimpan maksimum 500 baris per aset (tabel `asset_spec_changes`).

## SIMA Agent Helper: pengumuman dan chat dari IT (agent v8)

Program kecil `SIMA-Agent-Helper.exe` berjalan di **sesi pengguna** (tanpa UAC, ikon tray, jalan otomatis saat login lewat
`HKLM\...\Run`). Kode: `helper-app/HelperApp.cs`; build: `helper-app\build.ps1`.

- **Pengumuman & kampanye:** menu **Pengumuman** di SIMA (hanya admin): judul, isi (teks biasa), tingkat (informasi/penting/mendesak),
  opsi "wajib dikonfirmasi", sasaran (semua perangkat atau perangkat tertentu), jadwal tayang, pratinjau langsung, dan
  laporan per perangkat (tampil/dikonfirmasi). Muncul di laptop sebagai kartu dalam sekitar 20 detik.
- **Chat, IT yang memulai:** tab **Aksi** di halaman detail agent. Hanya IT (hak ubah aset) yang bisa **memulai dan menutup**
  percakapan; pengguna hanya membalas. Tombol tutup (X) di jendela chat laptop hanya menyembunyikannya ke tray. Sesi
  diam 30 menit ditutup otomatis. Semua pesan tersimpan dengan nama pengirim dan waktu.
- **Token khusus:** program ini TIDAK memakai token agent (berkas `config.json` terkunci untuk SYSTEM/Administrators).
  Server memberi token terpisah (cakupan hanya pengumuman dan chat aset itu, berumur 48 jam, dirotasi otomatis lewat jawaban
  heartbeat). Agent menulisnya ke `C:\ProgramData\SIMA\helper.json` (tulis: SYSTEM/Administrators, baca: Users).
  Mencabut token agent langsung memutus program pembantu.
- **Polling:** 20 detik saat idle, 3 detik selama chat terbuka (cukup untuk ratusan laptop tanpa WebSocket).

**Rollout:** program pembantu dipasang lewat **installer baru** (laptop lama pasang ulang sekali). Daftar file yang boleh
datang lewat update otomatis di agent v8 sudah memuat `SIMA-Agent-Helper.exe`, jadi pembaruan program pembantu berikutnya
bisa dikirim lewat rilis update. Jangan memasukkannya ke paket update **sebelum** semua laptop berada di v8: agent v7
ke bawah menolak paket yang berisi berkas itu (laptop tetap aman, tetapi update-nya gagal).

## Installer tiga bahasa (Indonesia, Inggris, Korea) dan cara membangunnya

- **Bahasa:** dialog pilih bahasa selalu tampil dan **Indonesia terpilih lebih dulu** (tidak mengikuti bahasa Windows).
  Seluruh teks (wizard, halaman koneksi, pesan galat, ringkasan, pintasan) dan halaman penjelasan transparansi
  (`info-before.id.txt`, `info-before.en.txt`, `info-before.ko.txt`) tersedia dalam tiga bahasa. Teks kustom ada di
  `[CustomMessages]` di `SIMA-Asset-Agent.iss`; terjemahan Indonesia dari `languages\Indonesian.isl` (terjemahan tidak resmi
  Inno Setup, disalin ke repo supaya build tidak bergantung pada unduhan), Inggris dan Korea bawaan Inno Setup.
  **Berkas `.iss` dan `info-before.*.txt` harus tetap UTF-8 dengan BOM** agar teks Korea terbaca benar.
- **Tampilan:** gaya modern, sedikit lebih lega (115%), gambar samping dan kecil bermerek AKP dalam tiga ukuran DPI
  (netral bahasa; dibuat oleh `tools\generate-wizard-images.ps1`).
- **Pemeriksaan sebelum memasang:** halaman "Hubungkan ke SIMA" menguji alamat dan token ke server (`GET /api/agent/v1/update`).
  Token ditolak (401/403) = instalasi berhenti di halaman itu. Server tidak terjangkau atau menjawab aneh = installer bertanya
  dulu, jadi pemasangan tanpa jaringan tetap bisa. Halaman "Siap memasang" merangkum server, 4 karakter terakhir token,
  jadwal, dan program pembantu.
- **Build satu perintah:** `powershell -ExecutionPolicy Bypass -File build-installer.ps1` membangun aplikasi status,
  SIMA Agent Helper, lalu installer. Versi di wizard diambil otomatis dari `$AgentVersion` di `agent-client\agent-update.ps1`.
  Butuh Inno Setup 6.5+ (ISCC.exe) dan `apps\Net Employe\Setup.exe` secara lokal.
