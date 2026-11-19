# SIMA Asset Agent (skrip klien, PowerShell)

Skrip teks biasa (bukan `.exe`) yang melaporkan status "online" sebuah aset ke SIMA. Lihat
[`../docs/rencana-agent-monitoring-aset.md`](../docs/rencana-agent-monitoring-aset.md) untuk rencana lengkap.

Data yang dikirim **hanya**: hostname, IP lokal, versi OS, sisa kapasitas disk C:. Tidak ada keylog,
screenshot, riwayat browsing, atau isi file apa pun.

## Trial lokal (1 laptop)

1. Di SIMA, buka halaman detail aset yang mewakili laptop ini (`http://localhost:8005/hardware/{id}`),
   klik **"Create token"** di baris **Agent Token**. Token ditampilkan **sekali** — salin sekarang.
2. Di folder ini:
   ```powershell
   Copy-Item config.example.json config.json
   notepad config.json
   ```
   Isi `simaUrl` (mis. `http://localhost:8005`) dan `token` (yang baru disalin).
3. Jalankan sekali secara manual untuk memastikan berhasil:
   ```powershell
   .\run-once.ps1
   ```
   Kalau berhasil, baris terakhir `agent.log` berbunyi `OK - hostname=... -> ok`, dan di halaman detail
   aset, **Agent Status** berubah jadi **Online**.
4. Kalau sudah sesuai harapan, pasang sebagai Scheduled Task supaya berjalan otomatis tiap 5 menit
   (jalankan PowerShell **sebagai Administrator**):
   ```powershell
   .\install.ps1
   ```
5. Untuk uji status **Offline**: hentikan task (`Stop-ScheduledTask -TaskName 'SIMA Asset Agent'` atau
   cabut token di SIMA), lalu tunggu job `agent:mark-stale-offline` di server (jalan tiap 5 menit, ambang
   15 menit sejak laporan terakhir — bisa dipercepat lewat `php artisan agent:mark-stale-offline --minutes=1`
   di server untuk uji cepat).

## Melepas trial

```powershell
.\uninstall.ps1
```
Lalu di SIMA, klik **"Revoke"** di baris Agent Token aset ini.

## File di folder ini

| File | Fungsi |
|---|---|
| `agent.ps1` | Skrip utama: kirim satu heartbeat, catat hasilnya ke `agent.log`. |
| `config.example.json` | Contoh konfigurasi. Salin jadi `config.json` dan isi — `config.json` **tidak** di-commit ke git (lihat `.gitignore`). |
| `run-once.ps1` | Jalankan sekali secara manual, untuk trial/uji. |
| `install.ps1` | Pasang sebagai Scheduled Task bernama **"SIMA Asset Agent"** (terlihat normal, bukan tersembunyi). |
| `uninstall.ps1` | Lepas Scheduled Task. |
| `agent.log` | Dibuat otomatis saat pertama jalan. Berisi riwayat setiap percobaan kirim — boleh dibuka kapan saja. |

## Kenapa PowerShell, bukan `.exe` terkompilasi

Lihat tabel perbandingan di `docs/rencana-agent-monitoring-aset.md` § Fase 2. Intinya: skrip teks biasa
bisa dibaca langsung oleh siapa pun di IT tanpa decompile, tidak perlu toolchain build terpisah, dan
`Get-CimInstance`/`Invoke-RestMethod` sudah bawaan Windows.
