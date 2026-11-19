<#
.SYNOPSIS
  TRIAL LOKAL: menjalankan agent.ps1 satu kali secara manual, untuk memastikan konfigurasi dan koneksi
  ke SIMA benar sebelum dipasang sebagai Scheduled Task (install.ps1).

.EXAMPLE
  cd agent-client
  Copy-Item config.example.json config.json
  notepad config.json   # isi simaUrl dan token
  .\run-once.ps1
  Get-Content .\agent.log -Tail 5
#>
$ErrorActionPreference = 'Stop'
& "$PSScriptRoot\agent.ps1"
Write-Host "`nIsi agent.log (5 baris terakhir):" -ForegroundColor Cyan
Get-Content -LiteralPath (Join-Path $PSScriptRoot 'agent.log') -Tail 5
