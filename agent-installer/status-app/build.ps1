<#
.SYNOPSIS
  Mengompilasi SIMA-Agent-Status.exe (WinForms) dengan csc.exe bawaan Windows (.NET Framework 4). Tanpa instalasi tambahan.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File build.ps1
#>
$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path $csc)) { throw "csc.exe tidak ditemukan: $csc" }

$logo = Join-Path $here '..\..\public\img\AKP.png'
$icon = Join-Path $here '..\assets\icon.ico'
$out = Join-Path $here 'bin'
New-Item -ItemType Directory -Force $out | Out-Null

& $csc /nologo /target:winexe /optimize+ /out:"$out\SIMA-Agent-Status.exe" `
    /win32icon:"$icon" /win32manifest:"$here\app.manifest" `
    /resource:"$logo,akp-logo.png" `
    /reference:System.dll /reference:System.Drawing.dll /reference:System.Windows.Forms.dll `
    "$here\StatusApp.cs"
if ($LASTEXITCODE -ne 0) { throw "Kompilasi gagal (kode $LASTEXITCODE)" }
Write-Host "OK: $out\SIMA-Agent-Status.exe" -ForegroundColor Green
