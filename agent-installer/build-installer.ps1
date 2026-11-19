<#
.SYNOPSIS
  Membangun SEMUANYA dengan satu perintah: aplikasi status, SIMA Agent Helper, lalu installer tiga bahasa
  (Indonesia, Inggris, Korea) di dist\SIMA-Asset-Agent-Setup.exe.

.DESCRIPTION
  Versi installer diambil otomatis dari $AgentVersion di agent-client\agent-update.ps1 (mis. 8 -> "8.0.0"),
  jadi nomor versi di halaman wizard dan di Programs and Features selalu sama dengan agent yang dipasang.
  Gambar wizard (assets\wizard-*.bmp) dibuat oleh tools\generate-wizard-images.ps1; pakai -RegenImages untuk membuat ulang.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File build-installer.ps1
  powershell -ExecutionPolicy Bypass -File build-installer.ps1 -RegenImages
#>
param(
    [string]$Version,
    [switch]$RegenImages
)

$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot

if (-not $Version) {
    $m = Select-String -Path (Join-Path $here '..\agent-client\agent-update.ps1') -Pattern '^\$AgentVersion\s*=\s*(\d+)' | Select-Object -First 1
    if (-not $m) { throw 'Tidak menemukan $AgentVersion di agent-client\agent-update.ps1' }
    $Version = '{0}.0.0' -f $m.Matches[0].Groups[1].Value
}

$iscc = @(
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'Inno Setup 6 (ISCC.exe) tidak ditemukan. Pasang dari https://jrsoftware.org/isinfo.php' }

foreach ($f in 'languages\Indonesian.isl', 'info-before.id.txt', 'info-before.en.txt', 'info-before.ko.txt') {
    if (-not (Test-Path (Join-Path $here $f))) { throw "Berkas wajib tidak ada: $f" }
}

if ($RegenImages -or -not (Test-Path (Join-Path $here 'assets\wizard-328.bmp'))) {
    Write-Host '== Gambar wizard' -ForegroundColor Cyan
    & (Join-Path $here 'tools\generate-wizard-images.ps1')
}

Write-Host '== Aplikasi status' -ForegroundColor Cyan
& (Join-Path $here 'status-app\build.ps1')
Write-Host '== SIMA Agent Helper' -ForegroundColor Cyan
& (Join-Path $here 'helper-app\build.ps1')

Write-Host "== Installer v$Version (id, en, ko)" -ForegroundColor Cyan
Push-Location $here
try {
    & $iscc "/DMyAppVersion=$Version" 'SIMA-Asset-Agent.iss'
    if ($LASTEXITCODE -ne 0) { throw "ISCC gagal (kode $LASTEXITCODE)" }
} finally { Pop-Location }

$out = Get-Item (Join-Path $here 'dist\SIMA-Asset-Agent-Setup.exe')
Write-Host ("Selesai: {0} ({1:N1} MB, v{2})" -f $out.FullName, ($out.Length / 1MB), $Version) -ForegroundColor Green
