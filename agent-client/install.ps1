<#
.SYNOPSIS
  Memasang SIMA Asset Agent sebagai Scheduled Task (nama jelas, terlihat normal di Task Scheduler),
  berjalan tiap beberapa menit.

.DESCRIPTION
  BUKAN proses tersembunyi: task ini bernama "SIMA Asset Agent", punya deskripsi yang menjelaskan fungsi
  dan kontak IT, dan bisa dilihat/dihapus siapa saja yang buka Task Scheduler. Dijalankan dengan hak
  SYSTEM (supaya tetap lapor walau tidak ada user login) tapi RunLevel Limited (tidak perlu admin penuh).

  Jalankan PowerShell sebagai Administrator untuk mendaftarkan task yang jalan sebagai SYSTEM.

.EXAMPLE
  .\install.ps1 -IntervalMinutes 5
#>
param(
    [int]$IntervalMinutes = 5,
    [string]$TaskName = 'SIMA Asset Agent'
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

if (-not (Test-Path (Join-Path $root 'config.json'))) {
    throw "config.json tidak ditemukan di $root. Salin config.example.json menjadi config.json dan isi dulu (lihat run-once.ps1)."
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$root\agent.ps1`""

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Jalankan PowerShell sebagai Administrator (klik kanan > Run as Administrator), lalu ulangi .\install.ps1. Task yang jalan sebagai SYSTEM butuh hak admin untuk didaftarkan."
}

# [TimeSpan]::MaxValue TIDAK valid untuk Task Scheduler (nilainya di luar jangkauan durasi ISO 8601 yang diterima).
# 10 tahun cukup sebagai "tanpa batas" dalam praktik.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)

$principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\SYSTEM' -LogonType ServiceAccount -RunLevel Limited

# StartWhenAvailable: jadwal yang terlewat (laptop mati/tidur) disusul begitu perangkat aktif lagi.
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description 'SIMA Asset Monitoring Agent: melapor status online/offline perangkat ini ke SIMA (sima.akp.co.id) setiap beberapa menit. Data yang dikirim: hostname, IP lokal, versi OS, sisa kapasitas disk. Kontak IT: it@akp.co.id.' `
    -Force -ErrorAction Stop | Out-Null

# Verifikasi sungguhan: jangan percaya begitu saja bahwa Register-ScheduledTask berhasil.
if (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) {
    throw "Task '$TaskName' tidak ditemukan setelah didaftarkan. Pendaftaran gagal."
}

# Kirim laporan pertama SEKARANG, tanpa menunggu interval pertama.
Start-ScheduledTask -TaskName $TaskName

Write-Host "Task '$TaskName' terpasang, berjalan tiap $IntervalMinutes menit." -ForegroundColor Green
Write-Host "Cek: Get-ScheduledTask -TaskName '$TaskName' | Get-ScheduledTaskInfo"
Write-Host "Jalankan segera (tanpa menunggu jadwal): Start-ScheduledTask -TaskName '$TaskName'"
Write-Host "Lepas: .\uninstall.ps1"
