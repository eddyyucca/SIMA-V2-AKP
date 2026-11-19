<#
.SYNOPSIS
  Membuat pasangan kunci RSA-3072 untuk menandatangani paket update SIMA Asset Agent.

.DESCRIPTION
  - Kunci PRIVAT disimpan di folder di LUAR repositori (bawaan: %USERPROFILE%\.sima-agent-keys) dengan hak akses hanya
    untuk akun ini. JANGAN commit, JANGAN kirim lewat chat/email, simpan cadangan terenkripsi di tempat aman (mis. password
    manager IT). Siapa pun yang memegang kunci ini bisa menjalankan kode sebagai SYSTEM di semua laptop yang terpasang agent.
  - Kunci PUBLIK dicetak untuk ditempel ke agent-client/agent-update.ps1 (konstanta $UpdatePublicKeyXml).
    Hanya dijalankan SEKALI. Mengganti kunci berarti semua laptop harus memasang ulang agent (atau menerima update
    yang ditandatangani kunci LAMA yang membawa kunci publik baru).
#>
param([string]$KeyDir = (Join-Path $env:USERPROFILE '.sima-agent-keys'))

$ErrorActionPreference = 'Stop'
$priv = Join-Path $KeyDir 'agent-signing-private.xml'
if (Test-Path $priv) { throw "Kunci sudah ada: $priv. Hapus manual jika memang ingin membuat ulang (semua agent harus dipasang ulang)." }

New-Item -ItemType Directory -Force $KeyDir | Out-Null
$rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider 3072
$rsa.PersistKeyInCsp = $false
Set-Content -LiteralPath $priv -Value $rsa.ToXmlString($true) -Encoding ascii
Set-Content -LiteralPath (Join-Path $KeyDir 'agent-signing-public.xml') -Value $rsa.ToXmlString($false) -Encoding ascii

# Batasi akses: hanya pemilik akun ini.
& icacls.exe $KeyDir /inheritance:r /grant:r "${env:USERNAME}:(OI)(CI)F" | Out-Null

Write-Host "Kunci privat : $priv" -ForegroundColor Yellow
Write-Host "Kunci publik : $(Join-Path $KeyDir 'agent-signing-public.xml')" -ForegroundColor Green
