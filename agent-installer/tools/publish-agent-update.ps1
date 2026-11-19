<#
.SYNOPSIS
  Membuat dan MENANDATANGANI paket update SIMA Asset Agent, lalu menulisnya ke agent-updates/ di repositori.

.DESCRIPTION
  Dijalankan di PC admin IT yang memegang kunci privat (lihat new-signing-key.ps1). Sesudah selesai:
      git add agent-updates && git commit -m "Rilis agent vN" && git push
  CI membangun image baru, stack di-redeploy, dan semua laptop mengambil update pada laporan berikutnya.

  Sebelum menjalankan: naikkan `$AgentVersion = N` di agent-client/agent-update.ps1 (harus sama dengan -Version).
  Paket berisi: agent.ps1, agent-update.ps1, dan (jika ada / -IncludeStatusApp) SIMA-Agent-Status.exe.

.EXAMPLE
  .\publish-agent-update.ps1 -Version 4 -Notes "Perbaikan pembacaan BitLocker" -IncludeStatusApp
#>
param(
    [Parameter(Mandatory)][int]$Version,
    [string]$Notes = '',
    [switch]$IncludeStatusApp,
    [string]$KeyFile,
    [string]$SourceDir,
    [string]$StatusExe,
    [string]$OutDir
)

# Default path dihitung di sini (bukan di blok param): $PSScriptRoot kosong di default parameter saat dijalankan dengan -File.
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $KeyFile)   { $KeyFile   = Join-Path $env:USERPROFILE '.sima-agent-keys\agent-signing-private.xml' }
if (-not $SourceDir) { $SourceDir = Join-Path $here '..\..\agent-client' }
if (-not $StatusExe) { $StatusExe = Join-Path $here '..\status-app\bin\SIMA-Agent-Status.exe' }
if (-not $OutDir)    { $OutDir    = Join-Path $here '..\..\agent-updates' }

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem

$SourceDir = (Resolve-Path $SourceDir).Path
$lib = Join-Path $SourceDir 'agent-update.ps1'
. $lib   # memuat $AgentVersion, Get-UpdateSignedMessage, Test-UpdateSignature

if ($AgentVersion -ne $Version) { throw "`$AgentVersion di agent-update.ps1 bernilai $AgentVersion, bukan $Version. Naikkan dulu konstanta itu, lalu jalankan ulang." }
if (-not (Test-Path -LiteralPath $KeyFile)) { throw "Kunci privat tidak ditemukan: $KeyFile" }

$files = @('agent.ps1', 'agent-update.ps1') | ForEach-Object { Join-Path $SourceDir $_ }
if ($IncludeStatusApp) {
    if (-not (Test-Path -LiteralPath $StatusExe)) { throw "SIMA-Agent-Status.exe belum dibangun: $StatusExe (jalankan status-app\build.ps1)" }
    $files += (Resolve-Path $StatusExe).Path
}

New-Item -ItemType Directory -Force $OutDir | Out-Null
$OutDir = (Resolve-Path $OutDir).Path
$zipPath = Join-Path $OutDir "agent-$Version.zip"
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

$zip = [IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    foreach ($f in $files) { [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f, (Split-Path $f -Leaf), 'Optimal') }
} finally { $zip.Dispose() }

$sha = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
$size = (Get-Item -LiteralPath $zipPath).Length

$rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider
$rsa.PersistKeyInCsp = $false
$rsa.FromXmlString((Get-Content -LiteralPath $KeyFile -Raw))
$sig = [Convert]::ToBase64String($rsa.SignData((Get-UpdateSignedMessage $Version $sha $size), 'SHA256'))

# Pemeriksaan ulang dengan kunci PUBLIK yang tertanam di agent: kalau ini gagal, kunci privat tidak berpasangan dengan agent.
if (-not (Test-UpdateSignature -Version $Version -Sha256 $sha -Size $size -Signature $sig)) {
    Remove-Item -LiteralPath $zipPath -Force
    throw 'Tanda tangan TIDAK cocok dengan kunci publik yang tertanam di agent-update.ps1. Rilis dibatalkan.'
}

$manifest = [ordered]@{
    version      = $Version
    file         = "agent-$Version.zip"
    sha256       = $sha
    size         = $size
    signature    = $sig
    notes        = $Notes
    published_at = (Get-Date).ToUniversalTime().ToString('o')
}
# UTF-8 TANPA BOM: Set-Content -Encoding utf8 di PowerShell 5.1 menulis BOM, dan json_decode PHP menolak JSON berawalan BOM.
[IO.File]::WriteAllText((Join-Path $OutDir 'manifest.json'), ($manifest | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))

# Simpan hanya 2 paket terakhir.
Get-ChildItem -LiteralPath $OutDir -Filter 'agent-*.zip' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 2 | Remove-Item -Force

Write-Host "Rilis v$Version siap: $zipPath ($([math]::Round($size / 1KB)) KB)" -ForegroundColor Green
Write-Host "SHA-256 : $sha"
Write-Host "Langkah berikut: git add agent-updates; git commit; git push" -ForegroundColor Yellow
