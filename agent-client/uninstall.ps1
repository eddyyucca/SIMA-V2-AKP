<#
.SYNOPSIS
  Mencopot SIMA Asset Agent: menghapus Scheduled Task. File agent (config.json, agent.log) TIDAK dihapus
  otomatis — hapus manual folder ini kalau memang ingin bersih total.
#>
param([string]$TaskName = 'SIMA Asset Agent')

$ErrorActionPreference = 'Stop'

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Task '$TaskName' dihapus." -ForegroundColor Green
} else {
    Write-Host "Task '$TaskName' tidak ditemukan (mungkin sudah dilepas)." -ForegroundColor Yellow
}
