#Requires -Version 5.1
<#
.SYNOPSIS
  Pembaruan otomatis SIMA Asset Agent (di-dot-source oleh agent.ps1). Hanya memasang paket yang ditandatangani AKP IT.

.DESCRIPTION
  Alur (dipanggil setelah laporan sukses terkirim, jadi update yang gagal TIDAK pernah menghalangi laporan):
    1. Server memberi tahu versi terbaru lewat jawaban heartbeat (latest_agent_version). Kalau tidak lebih baru: selesai.
    2. Agent meminta info paket (GET /api/agent/v1/update) dan mengunduh zip (GET /api/agent/v1/update/package).
    3. Verifikasi BERLAPIS, semuanya harus lolos:
         a. ukuran dan SHA-256 sama dengan yang diumumkan;
         b. tanda tangan RSA-3072 (SHA-256) atas "sima-agent-update|v1|versi|sha256|ukuran" sah menurut kunci PUBLIK di bawah.
            Kunci privat TIDAK ada di server maupun repositori: server yang dibobol tidak bisa mendorong kode ke laptop;
         c. isi zip hanya berkas yang diizinkan (tanpa folder/path), ukuran wajar;
         d. skrip lolos pengecekan sintaks dan konstanta $AgentVersion di dalamnya sama dengan versi yang diumumkan;
         e. uji-jalan di proses terpisah (agent.ps1 -SelfTest) sebelum dipasang.
    4. Salinan lama disimpan di folder backup\vN, berkas diganti, kalau ada yang gagal semuanya dikembalikan.
  Versi hanya boleh NAIK (anti-downgrade). Gagal berulang ditunda 60 menit supaya tidak membebani server.

  Berkas yang sedang dipakai (mis. SIMA-Agent-Status.exe yang terbuka) disimpan sebagai *.new dan dipasang pada run berikutnya.
#>

# Versi skrip ini. WAJIB dinaikkan tiap rilis (publish-agent-update.ps1 memeriksa kecocokannya dengan nomor rilis).
$AgentVersion = 9
# Kunci PUBLIK penanda tangan rilis (pasangan kunci privat disimpan offline oleh IT AKP, lihat agent-installer/tools).
$script:UpdatePublicKeyXml = '<RSAKeyValue><Modulus>0gPkrMEA0nqg4Rel3lXuKUkWytaaFYJnFm5kSxo9eu9OhPDimoqqVOk0yJ6/AP6y1q+LxRhmgJUXcWdIX4OGS6pWXDTdxqU3zRZ51c0HdhKmwazgDjQRHGKfIWIC7LgTAgPTuYXOtW4xKFiudQo1fswkQNXLS4dzJSlMzWdlm64WFjZUbbXWB7Xi3wzynjbFudplvNBuyEQjTOe6y+HcehL/C8kVlrChuIumdNLVu0BSZRdK3TQm122bizCwQVHdsBPkSJy6wDwb5bVWUiimm0C2cUamA2wx9jAFCH2fMeV8/Fs43FaPGzoV86k/33gptvVEssjW1a0NRae/EKOeIi5VFQnJ6OmKZWp+wZBQSaG3m5ftkPgue+MqpHrjRR3+pugCuCGooMm1kadJk3H92XkYU+n+0JSSXt/1+B/VOpkjZmuRhSY5YKD7dweIltXunTOpEKS6juvH+zW9rHYeNwoBIsVP5DHTQhPPXu2FP9Ww1RFzeYzoM4mOt1oalHEZ</Modulus><Exponent>AQAB</Exponent></RSAKeyValue>'

# Hanya berkas ini yang boleh datang dari paket update (nama polos, tanpa path).
$script:AllowedUpdateFiles = @('agent.ps1', 'agent-update.ps1', 'SIMA-Agent-Status.exe', 'SIMA-Agent-Helper.exe')
$script:RequiredUpdateFiles = @('agent.ps1', 'agent-update.ps1')
$script:MaxUpdateFileBytes = 30MB

function Write-UpdateLog([string]$Message) {
    if (Get-Command Write-AgentLog -ErrorAction SilentlyContinue) { Write-AgentLog $Message } else { Write-Host $Message }
}

function Get-UpdateSignedMessage([int]$Version, [string]$Sha256, [long]$Size) {
    [Text.Encoding]::UTF8.GetBytes(("sima-agent-update|v1|{0}|{1}|{2}" -f $Version, $Sha256.ToLowerInvariant(), $Size))
}

function Test-UpdateSignature {
    param([int]$Version, [string]$Sha256, [long]$Size, [string]$Signature, [string]$PublicKeyXml = $script:UpdatePublicKeyXml)
    try {
        $rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider
        $rsa.FromXmlString($PublicKeyXml)
        $sig = [Convert]::FromBase64String($Signature)
        return [bool]$rsa.VerifyData((Get-UpdateSignedMessage $Version $Sha256 $Size), 'SHA256', $sig)
    } catch { return $false }
}

# Memeriksa zip: berkas diizinkan saja, ukuran wajar, lalu mengekstrak ke $StagingDir. Melempar exception kalau ada pelanggaran.
function Expand-UpdatePackage([string]$ZipPath, [string]$StagingDir) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $seen = @{}
        foreach ($e in $zip.Entries) {
            if ($e.FullName.EndsWith('/') -or $e.FullName.EndsWith('\')) { throw "paket berisi folder: $($e.FullName)" }
            if ($script:AllowedUpdateFiles -cnotcontains $e.FullName) { throw "berkas tidak diizinkan di paket: $($e.FullName)" }
            if ($e.Length -gt $script:MaxUpdateFileBytes) { throw "berkas terlalu besar: $($e.FullName)" }
            $seen[$e.FullName] = $true
        }
        foreach ($r in $script:RequiredUpdateFiles) { if (-not $seen.ContainsKey($r)) { throw "paket tidak memuat $r" } }
        New-Item -ItemType Directory -Force $StagingDir | Out-Null
        foreach ($e in $zip.Entries) {
            $dest = Join-Path $StagingDir $e.FullName
            $in = $e.Open()
            try {
                $out = [IO.File]::Create($dest)
                try { $in.CopyTo($out) } finally { $out.Dispose() }
            } finally { $in.Dispose() }
        }
    } finally { $zip.Dispose() }
}

# Memastikan skrip hasil ekstrak valid sebelum menggantikan yang sedang berjalan.
function Test-StagedUpdate([string]$StagingDir, [int]$Version) {
    foreach ($f in @('agent.ps1', 'agent-update.ps1')) {
        $errs = $null
        [void][System.Management.Automation.Language.Parser]::ParseFile((Join-Path $StagingDir $f), [ref]$null, [ref]$errs)
        if ($errs -and $errs.Count -gt 0) { throw "kesalahan sintaks di ${f}: $($errs[0].Message)" }
    }
    $lib = Get-Content -LiteralPath (Join-Path $StagingDir 'agent-update.ps1') -Raw
    $m = [regex]::Match($lib, '(?m)^\$AgentVersion\s*=\s*(\d+)\s*$')
    if (-not $m.Success -or [int]$m.Groups[1].Value -ne $Version) { throw "konstanta `$AgentVersion di paket tidak sama dengan versi yang diumumkan ($Version)" }

    $psi = New-Object Diagnostics.ProcessStartInfo 'powershell.exe',
        ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}" -SelfTest' -f (Join-Path $StagingDir 'agent.ps1'))
    $psi.UseShellExecute = $false; $psi.CreateNoWindow = $true; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true
    $p = [Diagnostics.Process]::Start($psi)
    $stdout = $p.StandardOutput.ReadToEnd()
    if (-not $p.WaitForExit(40000)) { try { $p.Kill() } catch {}; throw 'uji-jalan (SelfTest) melewati batas waktu' }
    if ($p.ExitCode -ne 0 -or $stdout.Trim() -ne "SELFTEST OK v$Version") { throw "uji-jalan (SelfTest) gagal: kode $($p.ExitCode), keluaran '$($stdout.Trim())'" }
}

# Memasang berkas *.new yang tertunda (mis. aplikasi status yang tadinya sedang terbuka).
function Complete-PendingUpdates([string]$Root) {
    foreach ($f in (Get-ChildItem -LiteralPath $Root -Filter '*.new' -File -ErrorAction SilentlyContinue)) {
        $target = Join-Path $Root ($f.Name -replace '\.new$', '')
        if ($script:AllowedUpdateFiles -cnotcontains (Split-Path $target -Leaf)) { continue }
        try {
            Move-Item -LiteralPath $f.FullName -Destination $target -Force -ErrorAction Stop
            Write-UpdateLog "UPDATE OK berkas tertunda dipasang: $(Split-Path $target -Leaf)"
        } catch { }   # masih terpakai: coba lagi di run berikutnya
    }
}

function Invoke-AgentUpdate {
    param([string]$Root, [string]$SimaUrl, [string]$Token, [int]$LatestVersion)

    $statePath = Join-Path $Root 'update-state.json'
    $stage = Join-Path $Root 'update-tmp'
    try {
        if ($LatestVersion -le $AgentVersion) { return }

        # Backoff: kalau versi ini baru saja gagal, jangan coba lagi sebelum 60 menit.
        if (Test-Path -LiteralPath $statePath) {
            try {
                $st = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
                if ([int]$st.failed_version -eq $LatestVersion -and ((Get-Date) - [datetime]$st.failed_at).TotalMinutes -lt 60) { return }
            } catch { }
        }

        Write-UpdateLog "UPDATE tersedia: v$AgentVersion -> v$LatestVersion, memeriksa paket..."
        $headers = @{ Authorization = "Bearer $Token" }
        $base = "$($SimaUrl.TrimEnd('/'))/api/agent/v1/update"
        $info = Invoke-RestMethod -Uri "${base}?current=$AgentVersion" -Headers $headers -TimeoutSec 20
        if (-not $info.update) { return }

        $ver = [int]$info.version
        $size = [long]$info.size
        $sha = ([string]$info.sha256).ToLowerInvariant()
        if ($ver -le $AgentVersion) { throw "versi diumumkan ($ver) tidak lebih baru dari yang terpasang ($AgentVersion)" }
        if ($sha -notmatch '^[0-9a-f]{64}$') { throw 'format SHA-256 tidak valid' }
        if ($size -le 0 -or $size -gt 60MB) { throw "ukuran paket tidak wajar: $size" }
        # Tanda tangan diperiksa SEBELUM mengunduh, sehingga server palsu bahkan tidak sempat mengirim berkas.
        if (-not (Test-UpdateSignature -Version $ver -Sha256 $sha -Size $size -Signature ([string]$info.signature))) {
            throw 'TANDA TANGAN TIDAK SAH - paket ditolak'
        }

        if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
        New-Item -ItemType Directory -Force $stage | Out-Null
        $zip = Join-Path $stage 'package.zip'
        Invoke-WebRequest -Uri "$base/package?version=$ver" -Headers $headers -OutFile $zip -TimeoutSec 180 -UseBasicParsing

        if ((Get-Item -LiteralPath $zip).Length -ne $size) { throw 'ukuran unduhan tidak sama dengan yang diumumkan' }
        if ((Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $sha) { throw 'SHA-256 unduhan tidak cocok' }

        $files = Join-Path $stage 'files'
        Expand-UpdatePackage -ZipPath $zip -StagingDir $files
        Test-StagedUpdate -StagingDir $files -Version $ver

        # Cadangan versi lama (satu folder per versi, hanya 2 terakhir disimpan).
        $backup = Join-Path $Root "backup\v$AgentVersion"
        New-Item -ItemType Directory -Force $backup | Out-Null
        foreach ($n in $script:AllowedUpdateFiles) {
            $cur = Join-Path $Root $n
            if (Test-Path -LiteralPath $cur) { Copy-Item -LiteralPath $cur -Destination $backup -Force }
        }

        $replaced = New-Object Collections.ArrayList
        try {
            foreach ($f in (Get-ChildItem -LiteralPath $files -File)) {
                $target = Join-Path $Root $f.Name
                $isScript = $f.Extension -eq '.ps1'
                try {
                    $tmpNew = "$target.new"
                    Copy-Item -LiteralPath $f.FullName -Destination $tmpNew -Force -ErrorAction Stop
                    Move-Item -LiteralPath $tmpNew -Destination $target -Force -ErrorAction Stop
                    [void]$replaced.Add($f.Name)
                } catch {
                    if ($isScript) { throw }      # skrip wajib terpasang semua, kalau tidak batalkan
                    # berkas non-skrip (exe) yang sedang terbuka: biarkan *.new, dipasang run berikutnya
                }
            }
        } catch {
            foreach ($n in $replaced) { Copy-Item -LiteralPath (Join-Path $backup $n) -Destination (Join-Path $Root $n) -Force -ErrorAction SilentlyContinue }
            throw "pemasangan dibatalkan dan versi lama dikembalikan: $($_.Exception.Message)"
        }

        $oldBackups = Get-ChildItem -LiteralPath (Join-Path $Root 'backup') -Directory -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -Skip 2
        foreach ($b in $oldBackups) { Remove-Item -LiteralPath $b.FullName -Recurse -Force -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
        Write-UpdateLog "UPDATE OK v$AgentVersion -> v$ver (aktif pada laporan berikutnya)"
    } catch {
        Write-UpdateLog "UPDATE GAGAL: $($_.Exception.Message)"
        try {
            @{ failed_version = $LatestVersion; failed_at = (Get-Date).ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
        } catch { }
    } finally {
        if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
