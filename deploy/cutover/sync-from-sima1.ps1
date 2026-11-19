<#
.SYNOPSIS
  (SERVER) Sinkron SIMA1 -> SIMA2 dalam satu perintah: dump terbaru SIMA1, impor (idempoten), rekonsiliasi, uji asap.
  SIMA1 TIDAK diubah dan tidak ada data yang dihapus: hanya baca dari SIMA1, hanya menambah/memperbarui di SIMA2.

.DESCRIPTION
  Langkah: (1) backup SIMA2 (pg_dump) + dump SIMA1 ke C:\akp-backup, (2) restore dump ke kontainer sementara 'sima-legacy-tmp'
  (snapshot konsisten, dibuat bila belum ada), (3) salin foto aset, (4) sima:import, (5) sima:reconcile, (6) sima:smoke.
  Aman dijalankan berulang kali: latihan sebelum hari-H, lalu sekali lagi setelah SIMA1 dibekukan.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File sync-from-sima1.ps1 -DryRun     # simulasi, semua perubahan dibatalkan
  powershell -ExecutionPolicy Bypass -File sync-from-sima1.ps1             # nyata
#>
param(
  [string]$SimaDb  = 'sima_akp-db-1',            # kontainer MariaDB SIMA1
  [string]$SimaDbName = 'sima_akp',
  [string]$Legacy  = 'sima-legacy-tmp',          # kontainer sementara (salinan SIMA1)
  [string]$Asset   = 'sima-v2-akp-asset-1',      # kontainer web SIMA2
  [string]$AssetDb = 'sima-v2-akp-db-1',         # kontainer PostgreSQL SIMA2
  [string]$Network = 'sima-v2-akp_default',
  [string]$Uploads = 'akp_sima_uploads',         # volume berkas SIMA1 (hanya dibaca)
  [string]$Backup  = 'C:\akp-backup',
  [switch]$DryRun,
  [switch]$SkipFiles
)
$ErrorActionPreference = 'Stop'
function Step($t) { Write-Host "`n== $t" -ForegroundColor Cyan }
function Run($cmd) { $out = & cmd /c "$cmd 2>&1"; if ($LASTEXITCODE -ne 0) { throw "Gagal: $cmd`n$out" }; $out }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
New-Item -ItemType Directory -Force $Backup | Out-Null

Step "1. Backup SIMA2 (sebelum impor) dan dump SIMA1 (hanya baca)"
Run "docker exec $AssetDb sh -c ""pg_dump -U akp_asset -d akp_asset -Fc -f /tmp/pre.dump""" | Out-Null
Run "docker cp ${AssetDb}:/tmp/pre.dump $Backup\akp_asset-pre-sync-$stamp.dump" | Out-Null
Run "docker exec $SimaDb sh -c ""mariadb-dump -uroot -p`$MARIADB_ROOT_PASSWORD --single-transaction --quick --routines --default-character-set=utf8mb4 $SimaDbName > /tmp/s.sql""" | Out-Null
Run "docker cp ${SimaDb}:/tmp/s.sql $Backup\sima_akp-$stamp.sql" | Out-Null
$size = (Get-Item "$Backup\sima_akp-$stamp.sql").Length
if ($size -lt 1MB) { throw "Dump SIMA1 terlalu kecil ($size byte): dibatalkan." }
if (-not (Select-String -Path "$Backup\sima_akp-$stamp.sql" -Pattern 'Dump completed' -Quiet)) { throw 'Dump SIMA1 tidak lengkap (tanpa penanda "Dump completed").' }
Write-Host ("  dump SIMA1 {0:N1} MB, backup SIMA2 tersimpan" -f ($size / 1MB))

Step "2. Siapkan salinan SIMA1 di kontainer sementara '$Legacy'"
$exists = (docker ps -a --format '{{.Names}}' | Where-Object { $_ -eq $Legacy })
if (-not $exists) {
  $pw = -join ((48..57 + 97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
  Run "docker run -d --name $Legacy --network $Network -e MARIADB_ROOT_PASSWORD=$pw mariadb:11.4" | Out-Null
} elseif (-not (docker ps --format '{{.Names}}' | Where-Object { $_ -eq $Legacy })) { Run "docker start $Legacy" | Out-Null }
$legacyPw = (docker exec $Legacy printenv MARIADB_ROOT_PASSWORD) -join ''
for ($i = 0; $i -lt 40; $i++) { docker exec $Legacy mariadb -uroot "-p$legacyPw" -e 'select 1' 2>$null | Out-Null; if ($LASTEXITCODE -eq 0) { break }; Start-Sleep 2 }
Run "docker cp $Backup\sima_akp-$stamp.sql ${Legacy}:/tmp/s.sql" | Out-Null
Run "docker exec $Legacy sh -c ""mariadb -uroot -p`$MARIADB_ROOT_PASSWORD -e 'drop database if exists $SimaDbName; create database $SimaDbName character set utf8mb4 collate utf8mb4_unicode_ci' && mariadb -uroot -p`$MARIADB_ROOT_PASSWORD --default-character-set=utf8mb4 $SimaDbName < /tmp/s.sql""" | Out-Null
Write-Host '  salinan siap'

$filesArg = ''
if (-not $SkipFiles) {
  Step "3. Salin foto aset (volume $Uploads, hanya baca)"
  docker rm -f sima-files-tmp 2>$null | Out-Null
  Run "docker create --name sima-files-tmp -v ${Uploads}:/u:ro ghcr.io/eddyyucca/akp-asset:latest true" | Out-Null
  Remove-Item -Recurse -Force "$Backup\sima-files" -ErrorAction SilentlyContinue
  New-Item -ItemType Directory -Force "$Backup\sima-files\public" | Out-Null
  Run "docker cp sima-files-tmp:/u $Backup\sima-files\public\uploads" | Out-Null
  docker rm sima-files-tmp 2>$null | Out-Null
  Run "docker exec $Asset rm -rf /tmp/sima-files" | Out-Null
  Run "docker cp $Backup\sima-files ${Asset}:/tmp/sima-files" | Out-Null
  Run "docker exec $Asset chmod -R a+rX /tmp/sima-files" | Out-Null
  $filesArg = '--files=/tmp/sima-files'
}

$envs = "-e APP_CONFIG_CACHE=/tmp/no-config-cache.php -e LEGACY_DB_HOST=$Legacy -e LEGACY_DB_PORT=3306 -e LEGACY_DB_DATABASE=$SimaDbName -e LEGACY_DB_USERNAME=root -e LEGACY_DB_PASSWORD=$legacyPw"
function Artisan($args) { & cmd /c "docker exec $envs $Asset runuser -u www-data -- php artisan $args 2>&1"; return $LASTEXITCODE }
Run "docker exec $Asset chown -R www-data:www-data storage" | Out-Null

Step ("4. sima:import " + $(if ($DryRun) { '(SIMULASI)' } else { '(NYATA)' }))
$flags = if ($DryRun) { '--dry-run' } else { '' }
$rc = Artisan "sima:import $flags $filesArg"
if ($rc -ne 0) { throw 'Impor GAGAL (dibatalkan seluruhnya oleh transaksi). Lihat pesan di atas.' }
if ($DryRun) { Write-Host "`nSimulasi selesai; tidak ada yang berubah." -ForegroundColor Green; exit 0 }

Step "5. sima:reconcile (gerbang)"
$rc = Artisan 'sima:reconcile'
Step "6. sima:smoke"
Artisan 'sima:smoke --skip-network' | Out-Null
$rc2 = Artisan 'sima:smoke --skip-network'

if ($rc -ne 0) { Write-Host "`nREKONSILIASI ADA SELISIH: jangan lanjut cutover sebelum dijelaskan." -ForegroundColor Red; exit 1 }
Write-Host "`nSinkron selesai: rekonsiliasi BERSIH. Backup: $Backup (*-$stamp*)" -ForegroundColor Green
