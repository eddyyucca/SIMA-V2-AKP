#Requires -Version 5.1
<#
.SYNOPSIS
  SIMA Asset Agent — melaporkan status "online" dan data detail perangkat ini ke SIMA, tiap beberapa menit.

.DESCRIPTION
  Dibaca config.json di folder yang sama (simaUrl + token per-aset, dibuat dari halaman detail aset di SIMA:
  tombol "Create token"). Mengirim lewat HTTPS/HTTP POST ke {simaUrl}/api/agent/v1/heartbeat:

    - Jaringan & OS   : hostname, IP lokal, versi/build/arsitektur OS, domain/workgroup, waktu boot terakhir
    - Hardware        : manufacturer, model, serial number (BIOS), CPU, RAM total, kapasitas disk C: (total & sisa)
    - Kepatuhan IT    : status BitLocker (enkripsi disk), status Windows Defender + umur definisi virus,
                        tanggal update Windows terakhir terpasang

  TIDAK mengirim apa pun selain itu: tidak ada keylog, screenshot, riwayat browsing, isi file, atau lokasi
  presisi. Tiap item dibaca dengan penangan error sendiri — satu item gagal (mis. BitLocker tidak tersedia
  di edisi Home) tidak menggagalkan laporan lainnya; nilainya cukup dikirim kosong.

  Skrip ini teks biasa (bukan .exe terkompilasi) supaya siapa pun di IT bisa membaca persis apa yang dikirim.
  Gagal kirim ke server (mis. tidak ada internet) dicatat ke agent.log dan dicoba lagi di siklus berikutnya —
  tidak ada popup ke user, tapi juga tidak disembunyikan: log selalu bisa dibuka.

  Lihat ../docs/rencana-agent-monitoring-aset.md untuk rencana lengkap (Fase 2).

.NOTES
  Untuk TRIAL LOKAL: jalankan manual dulu (lihat run-once.ps1), baru pasang sebagai Scheduled Task
  (lihat install.ps1) kalau hasilnya sudah sesuai harapan.
#>

param(
    [switch]$SelfTest,   # dipakai updater untuk menguji paket baru; keluar tanpa membaca config atau mengirim apa pun
    [switch]$DryRun      # cetak payload yang AKAN dikirim lalu keluar (tanpa mengirim); berguna untuk audit/pemecahan masalah
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$configPath = Join-Path $root 'config.json'
$logPath = Join-Path $root 'agent.log'

# Pustaka pembaruan otomatis (memuat $AgentVersion dan kunci publik penanda tangan rilis).
. (Join-Path $PSScriptRoot 'agent-update.ps1')
if ($SelfTest) { Write-Output "SELFTEST OK v$AgentVersion"; exit 0 }

function Write-AgentLog([string]$Message) {
    $line = "{0:yyyy-MM-dd HH:mm:ss}  {1}" -f (Get-Date), $Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding utf8
    # Rotasi sederhana: potong log kalau sudah > ~2 MB, supaya tidak tumbuh tanpa batas.
    if ((Test-Path $logPath) -and (Get-Item $logPath).Length -gt 2MB) {
        $tail = Get-Content $logPath -Tail 500
        Set-Content -LiteralPath $logPath -Value $tail -Encoding utf8
    }
}

# Menjalankan $Block; kalau gagal (mis. cmdlet tidak ada di edisi Windows ini), kembalikan $null diam-diam
# supaya satu item yang tidak tersedia tidak menggagalkan seluruh laporan.
function Get-Safely([scriptblock]$Block) {
    try { & $Block } catch { $null }
}

function Limit-Text($Text, [int]$Max) {
    $s = ([string]$Text).Trim()
    if ($s.Length -gt $Max) { $s.Substring(0, $Max) } else { $s }
}

# --- Jaringan: IP/gateway/MAC dari adapter yang memegang rute default (bukan adapter virtual WSL/VPN), SSID bila Wi-Fi ---
function Get-NetworkInfo {
    $r = @{ ip = $null; type = $null; ssid = $null; mac = $null; gateway = $null }
    $cfg = Get-Safely {
        Get-NetIPConfiguration -ErrorAction Stop |
            Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address -and $_.NetAdapter.Status -eq 'Up' } |
            Select-Object -First 1
    }
    if ($cfg) {
        $r.ip = [string]$cfg.IPv4Address[0].IPAddress
        $r.gateway = [string]$cfg.IPv4DefaultGateway[0].NextHop
        $r.mac = [string]$cfg.NetAdapter.MacAddress
        $desc = "$($cfg.NetAdapter.InterfaceDescription) $($cfg.NetAdapter.PhysicalMediaType) $($cfg.InterfaceAlias)"
        if ($desc -match 'Wireless|Wi-?Fi|802\.11|WLAN') { $r.type = 'wifi' } else { $r.type = 'ethernet' }
    }
    if ($r.type -eq 'wifi') {
        # Windows 11 24H2 bisa menyembunyikan SSID dari layanan tanpa izin lokasi; kalau kosong, biarkan kosong.
        $ssid = Get-Safely {
            foreach ($l in (netsh wlan show interfaces)) {
                if ($l -match '^\s*SSID\s*:\s*(.+)$') { $Matches[1].Trim(); break }
            }
        }
        if ($ssid) { $r.ssid = Limit-Text $ssid 191 }
    }
    $r
}

# --- Baterai: persen, status daya, dan kesehatan (kapasitas penuh saat ini dibanding kapasitas desain) ---
function Get-BatteryInfo {
    $r = @{ present = $false; percent = $null; charging = $null; health = $null }
    $b = Get-Safely { Get-CimInstance Win32_Battery -ErrorAction Stop | Select-Object -First 1 }
    if (-not $b) { return $r }
    $r.present = $true
    if ($null -ne $b.EstimatedChargeRemaining) { $r.percent = [int][math]::Min(100, [math]::Max(0, [int]$b.EstimatedChargeRemaining)) }
    # BatteryStatus: 2 = tersambung listrik, 6-9 = mengisi daya
    $r.charging = (@(2, 6, 7, 8, 9) -contains [int]$b.BatteryStatus)
    $full = Get-Safely { (Get-CimInstance -Namespace root\wmi -ClassName BatteryFullChargedCapacity -ErrorAction Stop | Select-Object -First 1).FullChargedCapacity }
    $design = Get-Safely { (Get-CimInstance -Namespace root\wmi -ClassName BatteryStaticData -ErrorAction Stop | Select-Object -First 1).DesignedCapacity }
    if ($full -and $design -and [double]$design -gt 0) { $r.health = [int][math]::Min(100, [math]::Round(100 * [double]$full / [double]$design)) }
    $r
}

# --- Disk: semua drive tetap + kesehatan disk fisik (Storage Spaces: Get-PhysicalDisk) ---
function Get-DiskInfo {
    $logical = @(Get-Safely { Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' -ErrorAction Stop } |
        ForEach-Object { @{ drive = [string]$_.DeviceID; total = [int64]$_.Size; free = [int64]$_.FreeSpace } })
    $physical = @(Get-Safely { Get-PhysicalDisk -ErrorAction Stop } | ForEach-Object {
        $h = 'unknown'
        switch ([string]$_.HealthStatus) { 'Healthy' { $h = 'healthy' } 'Warning' { $h = 'warning' } 'Unhealthy' { $h = 'unhealthy' } }
        @{ name = (Limit-Text $_.FriendlyName 191); media = (Limit-Text $_.MediaType 20); size = [int64]$_.Size; health = $h }
    })
    @{ logical = $logical; physical = $physical }
}

# --- Prosesor: produsen, core/thread, clock, cache, beban sesaat (Win32_Processor; mesin multi-socket dijumlah) ---
function Get-CpuInfo {
    $procs = @(Get-Safely { Get-CimInstance Win32_Processor -ErrorAction Stop })
    if ($procs.Count -eq 0) { return $null }
    $first = $procs[0]
    $cores = ($procs | Measure-Object -Property NumberOfCores -Sum).Sum
    $logical = ($procs | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum
    $load = ($procs | Where-Object { $null -ne $_.LoadPercentage } | Measure-Object -Property LoadPercentage -Average).Average
    $r = @{
        manufacturer = Limit-Text $first.Manufacturer 64
        cores        = if ($cores) { [int]$cores } else { $null }
        logical      = if ($logical) { [int]$logical } else { $null }
        max_mhz      = if ($first.MaxClockSpeed) { [int]$first.MaxClockSpeed } else { $null }
        current_mhz  = if ($first.CurrentClockSpeed) { [int]$first.CurrentClockSpeed } else { $null }
        load         = if ($null -ne $load) { [int][math]::Min(100, [math]::Round($load)) } else { $null }
        l2_kb        = if ($first.L2CacheSize) { [int]$first.L2CacheSize } else { $null }
        l3_kb        = if ($first.L3CacheSize) { [int]$first.L3CacheSize } else { $null }
    }
    $r
}

# --- Memori: sisa RAM saat ini, jumlah slot, kapasitas maksimum, dan detail tiap keping (Win32_PhysicalMemory) ---
function Get-MemoryInfo {
    $r = @{ free = $null; slots = $null; max = $null; modules = @() }
    $os = Get-Safely { Get-CimInstance Win32_OperatingSystem -ErrorAction Stop }
    if ($os -and $null -ne $os.FreePhysicalMemory) { $r.free = [int64]$os.FreePhysicalMemory * 1KB }   # nilainya dalam KB

    $arrays = @(Get-Safely { Get-CimInstance Win32_PhysicalMemoryArray -ErrorAction Stop })
    if ($arrays.Count -gt 0) {
        $slots = ($arrays | Measure-Object -Property MemoryDevices -Sum).Sum
        $maxKb = ($arrays | Measure-Object -Property MaxCapacity -Sum).Sum
        if ($slots) { $r.slots = [int][math]::Min(64, $slots) }
        if ($maxKb) { $r.max = [int64]$maxKb * 1KB }                                                       # MaxCapacity dalam KB
    }

    # SMBIOSMemoryType -> nama generasi RAM
    $types = @{ 20 = 'DDR'; 21 = 'DDR2'; 24 = 'DDR3'; 26 = 'DDR4'; 27 = 'LPDDR'; 28 = 'LPDDR2'; 29 = 'LPDDR3'; 30 = 'LPDDR4'; 34 = 'DDR5'; 35 = 'LPDDR5' }
    $forms = @{ 8 = 'DIMM'; 12 = 'SODIMM' }
    $mods = @(Get-Safely { Get-CimInstance Win32_PhysicalMemory -ErrorAction Stop } | ForEach-Object {
        $speed = if ($_.ConfiguredClockSpeed) { [int]$_.ConfiguredClockSpeed } elseif ($_.Speed) { [int]$_.Speed } else { $null }
        @{
            slot         = Limit-Text $(if ($_.DeviceLocator) { $_.DeviceLocator } else { $_.BankLabel }) 64
            capacity     = [int64]$_.Capacity
            speed_mhz    = $speed
            type         = $types[[int]$_.SMBIOSMemoryType]
            form_factor  = $forms[[int]$_.FormFactor]
            manufacturer = Limit-Text $_.Manufacturer 64
            part_number  = Limit-Text $_.PartNumber 64
        }
    })
    $r.modules = $mods
    $r
}

# --- Kepatuhan (HANYA BACA, tidak mengubah apa pun): anggota grup Administrators lokal, akun Administrator bawaan, kebijakan USB ---
# Mengembalikan $null bila tidak terbaca, supaya server menampilkan "tidak diketahui" dan bukan angka palsu.
function Get-LocalAdminInfo {
    $out = New-Object Collections.Generic.List[object]
    $list = Get-Safely { Get-LocalGroupMember -SID 'S-1-5-32-544' -ErrorAction Stop }
    if ($null -ne $list) {
        foreach ($m in @($list)) {
            $source = 'unknown'
            switch ([string]$m.PrincipalSource) {
                'Local' { $source = 'local' } 'ActiveDirectory' { $source = 'domain' }
                'AzureAD' { $source = 'azuread' } 'MicrosoftAccount' { $source = 'microsoft' }
            }
            $out.Add(@{ name = (Limit-Text $m.Name 191); type = $(if ([string]$m.ObjectClass -eq 'Group') { 'group' } else { 'user' }); source = $source })
        }
    } else {
        # Cadangan (mis. SID yatim membuat Get-LocalGroupMember gagal): WMI.
        $wmi = Get-Safely {
            Get-CimInstance Win32_Group -Filter "SID='S-1-5-32-544'" -ErrorAction Stop |
                Get-CimAssociatedInstance -Association Win32_GroupUser -ErrorAction Stop
        }
        if ($null -eq $wmi) { return $null }
        foreach ($m in @($wmi)) {
            $local = ([string]$m.Domain -ieq $env:COMPUTERNAME)
            $out.Add(@{
                name   = (Limit-Text "$($m.Domain)\$($m.Name)" 191)
                type   = $(if ($m.CimClass.CimClassName -eq 'Win32_Group') { 'group' } else { 'user' })
                source = $(if ($local) { 'local' } else { 'domain' })
            })
        }
    }
    , @($out | Select-Object -First 30)
}

# $true/$false bila akun Administrator bawaan (RID 500) aktif/nonaktif; $null bila tidak terbaca.
function Get-BuiltinAdminEnabled {
    $u = Get-Safely { Get-LocalUser -ErrorAction Stop | Where-Object { $_.SID.Value -like '*-500' } | Select-Object -First 1 }
    if ($u) { return [bool]$u.Enabled }
    $w = Get-Safely { Get-CimInstance Win32_UserAccount -Filter "LocalAccount=True AND SID LIKE '%-500'" -ErrorAction Stop | Select-Object -First 1 }
    if ($w) { return (-not [bool]$w.Disabled) }
    $null
}

# Keadaan penyimpanan USB menurut kebijakan Windows: blocked | write_blocked | allowed | $null (tak terbaca). Hanya membaca registry.
function Get-UsbStorageState {
    try {
        $policy = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices'
        $disk = Join-Path $policy '{53f5630d-b6bf-11d0-94f2-00a0c91efb8b}'
        $prop = { param($path, $name) (Get-ItemProperty -LiteralPath $path -Name $name -ErrorAction SilentlyContinue).$name }
        if ((& $prop $policy 'Deny_All') -eq 1) { return 'blocked' }
        if ((& $prop $disk 'Deny_Read') -eq 1) { return 'blocked' }
        $usbstor = & $prop 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR' 'Start'
        if ($usbstor -eq 4) { return 'blocked' }
        if ((& $prop $disk 'Deny_Write') -eq 1) { return 'write_blocked' }
        if ((& $prop 'HKLM:\SOFTWARE\Microsoft\PolicyManager\current\device\Storage' 'RemovableDiskDenyWriteAccess') -eq 1) { return 'write_blocked' }
        return 'allowed'
    } catch { return $null }
}

function Get-RebootPending {
    foreach ($k in 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired',
                   'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') {
        if (Test-Path $k) { return $true }
    }
    $false
}

# --- Inventaris harian: software terpasang (registry Uninstall 64+32 bit; tanpa komponen sistem & patch) ---
function Get-SoftwareInventory {
    $paths = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    $seen = @{}
    $list = New-Object Collections.ArrayList
    foreach ($item in (Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue)) {
        $name = Limit-Text $item.DisplayName 191
        if (-not $name) { continue }
        if ($item.SystemComponent -eq 1 -or $item.ParentKeyName) { continue }
        if ($name -match '^(KB\d{6,}|(Security|Hotfix) Update|Update for )') { continue }
        $ver = Limit-Text $item.DisplayVersion 64
        $key = "$name|$ver".ToLowerInvariant()
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        [void]$list.Add(@{ name = $name; version = $ver; publisher = (Limit-Text $item.Publisher 191) })
        if ($list.Count -ge 1500) { break }
    }
    $list | Sort-Object { $_.name }
}

# --- Windows Update tertunda lewat Windows Update Agent (COM). Dijalankan di job dengan batas waktu: pencarian bisa
# --- menggantung kalau WSUS/internet bermasalah. $null = tidak bisa diperiksa (bukan "0 update"). ---
function Get-PendingWindowsUpdates {
    $job = Start-Job -ScriptBlock {
        $session = New-Object -ComObject Microsoft.Update.Session
        $result = $session.CreateUpdateSearcher().Search("IsInstalled=0 and IsHidden=0 and Type='Software'")
        $titles = @()
        foreach ($u in $result.Updates) { $titles += [string]$u.Title }
        [pscustomobject]@{ count = [int]$result.Updates.Count; titles = $titles }
    }
    try {
        if (Wait-Job $job -Timeout 150) { return (Receive-Job $job -ErrorAction Stop) }
        return $null
    } catch {
        return $null
    } finally {
        Stop-Job $job -ErrorAction SilentlyContinue
        Remove-Job $job -Force -ErrorAction SilentlyContinue
    }
}

# --- Benchmark RAM: throughput memori dalam MB/s. Tulis = isi buffer besar dari pola kecil, baca = salin potongan buffer
# --- besar ke buffer kecil (keduanya memcpy native lewat [Buffer]::BlockCopy). Hasil terbaik dari 3 putaran. Ini
# --- PERKIRAAN throughput, bukan pengganti benchmark khusus. $null = RAM kosong kurang dari 1 GB (dilewati).
function Measure-RamSpeed {
    try {
        $os = Get-Safely { Get-CimInstance Win32_OperatingSystem -ErrorAction Stop }
        if (-not $os) { return $null }
        $freeMb = [int]($os.FreePhysicalMemory / 1024)
        if ($freeMb -lt 1024) { return $null }
        $sizeMb = [int][math]::Min(256, [math]::Floor($freeMb / 8))
        $chunk = 1048576
        $big = New-Object byte[] ($sizeMb * $chunk)
        $small = New-Object byte[] $chunk
        (New-Object Random).NextBytes($small)
        $bestW = 0.0; $bestR = 0.0
        for ($pass = 0; $pass -lt 3; $pass++) {
            $sw = [Diagnostics.Stopwatch]::StartNew()
            for ($i = 0; $i -lt $sizeMb; $i++) { [Buffer]::BlockCopy($small, 0, $big, $i * $chunk, $chunk) }
            $sw.Stop()
            $w = $sizeMb / [math]::Max(0.0001, $sw.Elapsed.TotalSeconds)
            $sw.Restart()
            for ($i = 0; $i -lt $sizeMb; $i++) { [Buffer]::BlockCopy($big, $i * $chunk, $small, 0, $chunk) }
            $sw.Stop()
            $r = $sizeMb / [math]::Max(0.0001, $sw.Elapsed.TotalSeconds)
            if ($w -gt $bestW) { $bestW = $w }
            if ($r -gt $bestR) { $bestR = $r }
        }
        return @{ read = [int][math]::Round($bestR); write = [int][math]::Round($bestW) }
    } catch {
        return $null
    }
}

# --- Benchmark disk (drive sistem): baca/tulis BERURUTAN 256 MB lewat Win32 CreateFile dengan FILE_FLAG_NO_BUFFERING dan
# --- WRITE_THROUGH, supaya yang terukur adalah perangkatnya, bukan cache OS. Berkas uji dihapus selesai dipakai.
# --- Dilewati bila ruang kosong < 2 GB. Dijalankan di job berbatas waktu (disk bermasalah bisa menggantung).
$script:DiskBenchSource = @'
using System;
using System.Diagnostics;
using System.Runtime.InteropServices;

public static class SimaDiskBench
{
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern IntPtr CreateFile(string name, uint access, uint share, IntPtr security, uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool WriteFile(IntPtr h, IntPtr buf, uint count, out uint written, IntPtr overlapped);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool ReadFile(IntPtr h, IntPtr buf, uint count, out uint read, IntPtr overlapped);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool CloseHandle(IntPtr h);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern IntPtr VirtualAlloc(IntPtr address, UIntPtr size, uint type, uint protect);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool VirtualFree(IntPtr address, UIntPtr size, uint type);

    public static double[] Run(string path, int mb)
    {
        const uint GENERIC_READ = 0x80000000, GENERIC_WRITE = 0x40000000, CREATE_ALWAYS = 2, OPEN_EXISTING = 3;
        const uint NO_BUFFERING = 0x20000000, WRITE_THROUGH = 0x80000000, SEQUENTIAL = 0x08000000;
        int chunk = 4 * 1024 * 1024;                       // kelipatan ukuran sektor, dan VirtualAlloc selaras halaman
        long total = (long)mb * 1024 * 1024;
        IntPtr buf = VirtualAlloc(IntPtr.Zero, (UIntPtr)chunk, 0x3000, 4);
        if (buf == IntPtr.Zero) throw new Exception("VirtualAlloc gagal");
        try
        {
            byte[] rnd = new byte[chunk];
            new Random().NextBytes(rnd);                   // data acak: tidak bisa dikompres/dedup controller disk
            Marshal.Copy(rnd, 0, buf, chunk);

            IntPtr h = CreateFile(path, GENERIC_WRITE, 0, IntPtr.Zero, CREATE_ALWAYS, NO_BUFFERING | WRITE_THROUGH | SEQUENTIAL, IntPtr.Zero);
            if (h == new IntPtr(-1)) throw new Exception("CreateFile (tulis) gagal, kode " + Marshal.GetLastWin32Error());
            double writeMbps;
            try
            {
                Stopwatch sw = Stopwatch.StartNew();
                long done = 0; uint n;
                while (done < total)
                {
                    if (!WriteFile(h, buf, (uint)chunk, out n, IntPtr.Zero)) throw new Exception("WriteFile gagal, kode " + Marshal.GetLastWin32Error());
                    done += n;
                }
                sw.Stop();
                writeMbps = mb / Math.Max(0.0001, sw.Elapsed.TotalSeconds);
            }
            finally { CloseHandle(h); }

            h = CreateFile(path, GENERIC_READ, 0, IntPtr.Zero, OPEN_EXISTING, NO_BUFFERING | SEQUENTIAL, IntPtr.Zero);
            if (h == new IntPtr(-1)) throw new Exception("CreateFile (baca) gagal, kode " + Marshal.GetLastWin32Error());
            double readMbps;
            try
            {
                Stopwatch sw = Stopwatch.StartNew();
                long done = 0; uint n;
                while (done < total)
                {
                    if (!ReadFile(h, buf, (uint)chunk, out n, IntPtr.Zero) || n == 0) break;
                    done += n;
                }
                sw.Stop();
                readMbps = (done / 1048576.0) / Math.Max(0.0001, sw.Elapsed.TotalSeconds);
            }
            finally { CloseHandle(h); }

            return new double[] { readMbps, writeMbps };
        }
        finally
        {
            VirtualFree(buf, UIntPtr.Zero, 0x8000);
            try { System.IO.File.Delete(path); } catch { }
        }
    }
}
'@

function Measure-DiskSpeed {
    $path = Join-Path $env:SystemRoot 'Temp\sima-bench.tmp'
    $job = $null
    try {
        $drive = Get-PSDrive -Name ($env:SystemDrive.TrimEnd(':')) -ErrorAction Stop
        if ($drive.Free -lt 2GB) { return $null }
        $job = Start-Job -ArgumentList $script:DiskBenchSource, $path -ScriptBlock {
            param($source, $file)
            Add-Type -TypeDefinition $source
            [SimaDiskBench]::Run($file, 256)
        }
        if (-not (Wait-Job $job -Timeout 120)) { return $null }
        $res = @(Receive-Job $job -ErrorAction Stop)
        if ($res.Count -lt 2) { return $null }
        return @{ read = [int][math]::Round($res[0]); write = [int][math]::Round($res[1]) }
    } catch {
        return $null
    } finally {
        if ($job) { Stop-Job $job -ErrorAction SilentlyContinue; Remove-Job $job -Force -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

# Mengirim inventaris (software + update tertunda + benchmark) paling sering sehari sekali, SESUDAH laporan utama.
# Gagal ditunda 60 menit supaya pemeriksaan Windows Update yang berat tidak diulang tiap 5 menit.
function Send-Inventory([string]$Root, $Config, [switch]$Force) {
    $statePath = Join-Path $Root 'agent-state.json'
    if (Test-Path -LiteralPath $statePath) {
        try {
            $st = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
            # -Force (perintah "kirim laporan lengkap" dari IT) melewati batas sehari sekali dan penundaan setelah gagal.
            if (-not $Force) {
                if ($st.inventory_at -and ((Get-Date) - [datetime]$st.inventory_at).TotalHours -lt 24) { return }
                if ($st.inventory_failed_at -and ((Get-Date) - [datetime]$st.inventory_failed_at).TotalMinutes -lt 60) { return }
            }
        } catch { }
    }
    $state = @{ inventory_at = $null; inventory_failed_at = $null }
    if ($st -and $st.inventory_at) { $state.inventory_at = [string]$st.inventory_at }
    try {
        $software = @(Get-SoftwareInventory)
        $wu = Get-PendingWindowsUpdates
        $payload = @{ software = $software; reboot_pending = (Get-RebootPending); agent_version = $AgentVersion }
        if ($wu) {
            $payload.pending_updates_count = $wu.count
            $payload.pending_updates = @($wu.titles | Select-Object -First 25 | ForEach-Object { Limit-Text $_ 255 })
        }
        $ram = Measure-RamSpeed
        if ($ram) { $payload.ram_read_mbps = $ram.read; $payload.ram_write_mbps = $ram.write }
        $disk = Measure-DiskSpeed
        if ($disk) { $payload.disk_read_mbps = $disk.read; $payload.disk_write_mbps = $disk.write }
        $json = $payload | ConvertTo-Json -Depth 6
        Invoke-RestMethod -Uri "$($Config.simaUrl.TrimEnd('/'))/api/agent/v1/heartbeat" -Method Post `
            -Headers @{ Authorization = "Bearer $($Config.token)" } `
            -Body ([Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8' -TimeoutSec 60 |
            ForEach-Object { if ($_.status -ne 'ok') { throw "server menolak inventaris: $($_.status) $($_.messages | ConvertTo-Json -Compress)" } }
        $state.inventory_at = (Get-Date).ToString('o')
        $updatesText = 'n/a'
        if ($wu) { $updatesText = [string]$wu.count }
        $benchText = 'ram=' + $(if ($ram) { "$($ram.read)/$($ram.write)" } else { 'n/a' }) + ' disk=' + $(if ($disk) { "$($disk.read)/$($disk.write)" } else { 'n/a' })
        $summary = "software=$($software.Count) updates=$updatesText $benchText MB/s"
        Write-AgentLog "INVENTORY OK $summary"
    } catch {
        $failure = $_.Exception.Message
        $state.inventory_failed_at = (Get-Date).ToString('o')
        Write-AgentLog "INVENTORY GAGAL: $failure"
    }
    $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
    if ($failure -and $Force) { throw $failure }
    return $summary
}

# ===== Token program pembantu (SIMA Agent Helper) =========================================================
# Program pembantu berjalan di sesi PENGGUNA (menampilkan pengumuman dan chat dari IT), jadi tidak bisa membaca token
# agent (berkas config.json dikunci untuk SYSTEM dan Administrators). Server memberi token KHUSUS yang hanya bisa untuk
# pengumuman dan chat aset ini, berumur 48 jam dan dirotasi otomatis. Agent menuliskannya ke berkas yang bisa DIBACA
# pengguna: C:\ProgramData\SIMA\helper.json (tulis hanya SYSTEM dan Administrators).
function Get-HelperFilePath {
    # $script:HelperFileOverride hanya diisi oleh skrip uji; produksi tidak pernah mengaturnya.
    if ($script:HelperFileOverride) { return $script:HelperFileOverride }
    Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SIMA\helper.json'
}

# $true bila berkas token ada dan masih berlaku; $false (= minta token baru ke server) bila hilang atau kedaluwarsa.
function Test-HelperFile {
    try {
        $f = Get-Content -LiteralPath (Get-HelperFilePath) -Raw -ErrorAction Stop | ConvertFrom-Json
        return ($f.token -and [datetime]$f.expires_at -gt (Get-Date))
    } catch { return $false }
}

function Save-HelperToken($Helper, [string]$SimaUrl) {
    $path = Get-HelperFilePath
    $dir = Split-Path $path -Parent
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force $dir | Out-Null }
    # SID tetap (tidak bergantung bahasa Windows): SYSTEM, Administrators = penuh; Users = baca saja.
    & icacls.exe $dir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
    $json = @{ simaUrl = $SimaUrl.TrimEnd('/'); token = [string]$Helper.token; expires_at = [string]$Helper.expires_at } | ConvertTo-Json
    [IO.File]::WriteAllText($path, $json, (New-Object Text.UTF8Encoding $false))
    Write-AgentLog "HELPER token program pembantu diperbarui (berlaku sampai $($Helper.expires_at))"
}

# ===== Perintah dari IT: DAFTAR TETAP =====================================================================
# Server hanya bisa meminta salah satu jenis di bawah; jenis lain ditolak. TIDAK ada eksekusi perintah atau skrip
# bebas: parameter divalidasi ketat, dan setiap perintah tercatat di agent.log (terlihat oleh pengguna laptop ini).
# Perintah diambil lewat jawaban heartbeat dan hasilnya dilaporkan ke /api/agent/v1/commands/{id}/result.
$script:AllowedCommandTypes = @(
    'refresh', 'collect_log', 'diagnostics', 'network_diagnostics', 'event_errors', 'startup_items',
    'defender_scan', 'defender_update', 'notify_user', 'restart_computer', 'cancel_restart'
)

# Menjalankan program bawaan Windows tanpa membiarkan stderr-nya menjadi pengecualian ($ErrorActionPreference global = Stop).
function Invoke-NativeTool([string]$Exe, [string[]]$Arguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $Exe @Arguments 2>&1
        return @{ code = $LASTEXITCODE; output = (($out | ForEach-Object { [string]$_ }) -join ' ').Trim() }
    } finally { $ErrorActionPreference = $previous }
}

function Get-ShutdownExe {
    # $script:ShutdownExe hanya diisi oleh skrip uji (agar uji TIDAK PERNAH me-restart mesin); produksi memakai shutdown.exe.
    if ($script:ShutdownExe) { return $script:ShutdownExe }
    'shutdown.exe'
}

function Invoke-OneCommand($Cmd, [string]$Root, $Config) {
    switch ([string]$Cmd.type) {
        'refresh' {
            $summary = Send-Inventory -Root $Root -Config $Config -Force
            return @{ status = 'done'; result = "Laporan lengkap terkirim: $summary" }
        }
        'collect_log' {
            $path = Join-Path $Root 'agent.log'
            if (-not (Test-Path -LiteralPath $path)) { return @{ status = 'failed'; result = 'agent.log belum ada' } }
            $text = (Get-Content -LiteralPath $path -Tail 300 -Encoding UTF8) -join "`n"
            return @{ status = 'done'; result = (Limit-Text $text 19000) }
        }
        'diagnostics' {
            $lines = New-Object Collections.Generic.List[string]
            $os = Get-Safely { Get-CimInstance Win32_OperatingSystem -ErrorAction Stop }
            $lines.Add("Waktu agent   : " + (Get-Date).ToString('o'))
            $lines.Add("Komputer      : $env:COMPUTERNAME")
            $lines.Add("Agent         : v$AgentVersion")
            $lines.Add("PowerShell    : $($PSVersionTable.PSVersion)")
            if ($os) {
                $lines.Add("Windows       : $($os.Caption) build $($os.BuildNumber)")
                $up = (Get-Date) - $os.LastBootUpTime
                $lines.Add("Nyala sejak   : $([math]::Floor($up.TotalHours)) jam lalu")
            }
            $drive = Get-Safely { Get-PSDrive -Name ($env:SystemDrive.TrimEnd(':')) -ErrorAction Stop }
            if ($drive) { $lines.Add("Disk sistem   : $([math]::Round($drive.Free / 1GB, 1)) GB kosong") }
            $task = Get-Safely { Get-ScheduledTask -TaskName 'SIMA Asset Agent' -ErrorAction Stop }
            if ($task) {
                $ti = Get-Safely { Get-ScheduledTaskInfo -TaskName 'SIMA Asset Agent' -ErrorAction Stop }
                $lines.Add("Scheduled Task: $($task.State); terakhir jalan $($ti.LastRunTime); hasil $($ti.LastTaskResult)")
            } else {
                $lines.Add("Scheduled Task: tidak ditemukan")
            }
            foreach ($svc in 'wuauserv', 'WinDefend', 'Winmgmt') {
                $s = Get-Safely { Get-Service -Name $svc -ErrorAction Stop }
                $lines.Add(("Layanan {0,-9}: {1}" -f $svc, $(if ($s) { $s.Status } else { 'tidak ada' })))
            }
            $lines.Add("msg.exe       : " + $(if (Get-Command msg.exe -ErrorAction SilentlyContinue) { 'tersedia' } else { 'tidak tersedia' }))
            $lines.Add("Token helper  : " + $(if (Test-HelperFile) { 'ada dan berlaku' } else { 'tidak ada / kedaluwarsa' }))
            $lines.Add("Program helper: " + $(if (Get-Process -Name 'SIMA-Agent-Helper' -ErrorAction SilentlyContinue) { 'berjalan' } else { 'tidak berjalan' }))
            $lines.Add("Server        : " + ([uri]$Config.simaUrl).Host)
            $lines.Add("Folder agent  : $Root")
            $lines.Add("Update gagal  : " + $(if (Test-Path (Join-Path $Root 'update-state.json')) { 'ada catatan (lihat agent.log)' } else { 'tidak ada' }))
            return @{ status = 'done'; result = ($lines -join "`n") }
        }
        'notify_user' {
            $text = ([string]$Cmd.params.message) -replace '[\x00-\x1F\x7F]', ' '
            $text = $text.Trim()
            if ($text.Length -gt 200) { $text = $text.Substring(0, 200) }
            if (-not $text) { return @{ status = 'failed'; result = 'pesan kosong' } }
            $msgExe = Get-Command msg.exe -ErrorAction SilentlyContinue
            if (-not $msgExe) { return @{ status = 'failed'; result = 'msg.exe tidak tersedia di Windows ini (mis. edisi Home)' } }
            # stderr msg.exe tidak boleh menjadi pengecualian ($ErrorActionPreference global = Stop).
            $previous = $ErrorActionPreference
            $ErrorActionPreference = 'Continue'
            try {
                $out = & $msgExe.Source '*' '/TIME:900' ("Pesan dari IT AKP: " + $text) 2>&1
                $code = $LASTEXITCODE
            } finally { $ErrorActionPreference = $previous }
            if ($code -ne 0) { return @{ status = 'failed'; result = "msg.exe gagal (kode ${code}): $($out -join ' ')" } }
            return @{ status = 'done'; result = 'Pesan ditampilkan ke pengguna yang sedang login' }
        }
        'network_diagnostics' {
            $lines = New-Object Collections.Generic.List[string]
            $net = Get-NetworkInfo
            $lines.Add("Koneksi       : " + $(if ($net.type) { $net.type } else { 'tidak diketahui' }))
            $lines.Add("IP / gateway  : $($net.ip) / $($net.gateway)")
            if ($net.ssid) { $lines.Add("Wi-Fi SSID    : $($net.ssid)") }
            $dnsServers = Get-Safely { (Get-DnsClientServerAddress -AddressFamily IPv4 -ErrorAction Stop | ForEach-Object { $_.ServerAddresses }) | Select-Object -Unique }
            if ($dnsServers) { $lines.Add("Server DNS    : " + (@($dnsServers) -join ', ')) }
            if ($net.gateway) {
                $ok = Get-Safely { Test-Connection -ComputerName $net.gateway -Count 2 -Quiet -ErrorAction Stop }
                $lines.Add("Ping gateway  : " + $(if ($ok) { 'berhasil' } else { 'GAGAL' }))
            }
            $u = [uri]$Config.simaUrl
            $watch = [Diagnostics.Stopwatch]::StartNew()
            $addr = Get-Safely { [Net.Dns]::GetHostAddresses($u.Host) | Select-Object -First 3 }
            $lines.Add("DNS $($u.Host) : " + $(if ($addr) { (@($addr) -join ', ') + " ($($watch.ElapsedMilliseconds) ms)" } else { 'GAGAL' }))
            if ($addr) {
                $tcp = New-Object Net.Sockets.TcpClient
                try {
                    $watch.Restart()
                    $iar = $tcp.BeginConnect($u.Host, $u.Port, $null, $null)
                    $open = $iar.AsyncWaitHandle.WaitOne(5000) -and $tcp.Connected
                    $lines.Add("TCP port $($u.Port)  : " + $(if ($open) { "terhubung ($($watch.ElapsedMilliseconds) ms)" } else { 'GAGAL / waktu habis' }))
                } catch {
                    $lines.Add("TCP port $($u.Port)  : GAGAL ($($_.Exception.Message))")
                } finally { $tcp.Close() }
            }
            return @{ status = 'done'; result = (Limit-Text ($lines -join "`n") 19000) }
        }
        'event_errors' {
            $events = @(Get-Safely {
                Get-WinEvent -FilterHashtable @{ LogName = @('System', 'Application'); Level = @(1, 2); StartTime = (Get-Date).AddHours(-24) } -MaxEvents 25 -ErrorAction Stop
            })
            if ($events.Count -eq 0) { return @{ status = 'done'; result = 'Tidak ada kejadian Error/Critical dalam 24 jam terakhir (atau log tidak terbaca).' } }
            $lines = foreach ($e in $events) {
                $first = ([string]$e.Message -split "`r?`n")[0]
                "{0:yyyy-MM-dd HH:mm} [{1}] {2} (ID {3}): {4}" -f $e.TimeCreated, $e.LogName, $e.ProviderName, $e.Id, (Limit-Text $first 200)
            }
            return @{ status = 'done'; result = (Limit-Text (@($lines) -join "`n") 19000) }
        }
        'startup_items' {
            $items = @(Get-Safely { Get-CimInstance Win32_StartupCommand -ErrorAction Stop })
            if ($items.Count -eq 0) { return @{ status = 'done'; result = 'Tidak ada program startup yang terdaftar (atau tidak terbaca).' } }
            $lines = foreach ($i in ($items | Select-Object -First 100)) {
                "{0} | {1} | {2}" -f (Limit-Text $i.Name 60), (Limit-Text $i.Command 200), (Limit-Text $i.Location 60)
            }
            return @{ status = 'done'; result = (Limit-Text (@($lines) -join "`n") 19000) }
        }
        'defender_scan' {
            # $script:MpCmdRunPath hanya diisi oleh skrip uji.
            $exe = if ($script:MpCmdRunPath) { $script:MpCmdRunPath } else { Join-Path $env:ProgramFiles 'Windows Defender\MpCmdRun.exe' }
            if (-not (Test-Path -LiteralPath $exe)) { return @{ status = 'failed'; result = 'MpCmdRun.exe tidak ditemukan (Windows Defender tidak terpasang / digantikan antivirus lain)' } }
            Start-Process -FilePath $exe -ArgumentList '-Scan', '-ScanType', '1' -WindowStyle Hidden | Out-Null
            return @{ status = 'done'; result = 'Quick Scan Windows Defender dimulai di latar belakang (hasilnya dilihat di Windows Security).' }
        }
        'defender_update' {
            if (-not (Get-Command Update-MpSignature -ErrorAction SilentlyContinue)) { return @{ status = 'failed'; result = 'Update-MpSignature tidak tersedia (Defender tidak aktif)' } }
            $job = Start-Job -ScriptBlock { Update-MpSignature -ErrorAction Stop }
            try {
                if (-not (Wait-Job $job -Timeout 120)) { return @{ status = 'failed'; result = 'Pembaruan definisi melewati batas waktu 120 detik' } }
                Receive-Job $job -ErrorAction Stop | Out-Null
            } catch {
                return @{ status = 'failed'; result = "Pembaruan definisi gagal: $($_.Exception.Message)" }
            } finally { Remove-Job $job -Force -ErrorAction SilentlyContinue }
            $mp = Get-Safely { Get-MpComputerStatus -ErrorAction Stop }
            $ver = if ($mp) { "$($mp.AntivirusSignatureVersion) ($($mp.AntivirusSignatureLastUpdated))" } else { 'versi tidak terbaca' }
            return @{ status = 'done'; result = "Definisi antivirus diperbarui: $ver" }
        }
        'restart_computer' {
            # Validasi ulang di sisi laptop: jeda 5-120 menit WAJIB, pesan dibersihkan. Server tidak bisa menyuruh restart seketika.
            $delay = 0
            if (-not [int]::TryParse([string]$Cmd.params.delay_minutes, [ref]$delay) -or $delay -lt 5 -or $delay -gt 120) {
                return @{ status = 'failed'; result = 'jeda restart harus 5-120 menit' }
            }
            $note = (([string]$Cmd.params.message) -replace '[\x00-\x1F\x7F"]', ' ').Trim()
            if ($note.Length -gt 150) { $note = $note.Substring(0, 150) }
            $text = "IT AKP menjadwalkan restart komputer ini dalam $delay menit. Simpan pekerjaan Anda."
            if ($note) { $text += " Pesan: $note" }
            $r = Invoke-NativeTool (Get-ShutdownExe) @('/r', '/t', [string]($delay * 60), '/c', $text)
            if ($r.code -eq 1190) { return @{ status = 'failed'; result = 'sudah ada restart/shutdown terjadwal di laptop ini' } }
            if ($r.code -ne 0) { return @{ status = 'failed'; result = "shutdown.exe gagal (kode $($r.code)): $($r.output)" } }
            $at = (Get-Date).AddMinutes($delay).ToString('yyyy-MM-dd HH:mm')
            return @{ status = 'done'; result = "Restart dijadwalkan pada $at ($delay menit lagi). Pengguna melihat peringatan Windows." }
        }
        'cancel_restart' {
            $r = Invoke-NativeTool (Get-ShutdownExe) @('/a')
            if ($r.code -eq 1116) { return @{ status = 'done'; result = 'Tidak ada restart terjadwal untuk dibatalkan' } }
            if ($r.code -ne 0) { return @{ status = 'failed'; result = "shutdown.exe /a gagal (kode $($r.code)): $($r.output)" } }
            return @{ status = 'done'; result = 'Restart terjadwal dibatalkan' }
        }
        default {
            return @{ status = 'failed'; result = "jenis perintah tidak dikenal: $($Cmd.type)" }
        }
    }
}

# Menjalankan perintah yang BELUM pernah dijalankan (id dicatat di agent-commands.json supaya tidak dobel walau server
# mengirimnya lagi), maksimal 3 per laporan, lalu melaporkan hasilnya. Hasil yang gagal terkirim dicoba lagi nanti.
function Invoke-AgentCommands($Commands, [string]$Root, $Config) {
    $statePath = Join-Path $Root 'agent-commands.json'
    $done = @{}
    if (Test-Path -LiteralPath $statePath) {
        try {
            $raw = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
            foreach ($p in $raw.PSObject.Properties) {
                $done[$p.Name] = @{ status = [string]$p.Value.status; result = [string]$p.Value.result; at = [string]$p.Value.at; posted = [bool]$p.Value.posted }
            }
        } catch { }
    }

    $ran = 0
    foreach ($cmd in @($Commands)) {
        if (-not $cmd -or $null -eq $cmd.id) { continue }
        $key = [string][int]$cmd.id
        if (-not $done.ContainsKey($key)) {
            if ($ran -ge 3) { continue }
            if ($script:AllowedCommandTypes -notcontains [string]$cmd.type) {
                $res = @{ status = 'failed'; result = "jenis perintah ditolak (tidak ada di daftar tetap): $($cmd.type)" }
            } else {
                try { $res = Invoke-OneCommand $cmd $Root $Config }
                catch { $res = @{ status = 'failed'; result = "kesalahan: $($_.Exception.Message)" } }
            }
            $ran++
            $done[$key] = @{ status = [string]$res.status; result = [string]$res.result; at = (Get-Date).ToString('o'); posted = $false }
            Write-AgentLog "COMMAND #$key $($cmd.type) -> $($res.status)"
        }
        if (-not $done[$key].posted) {
            try {
                $body = @{ status = $done[$key].status; result = $done[$key].result } | ConvertTo-Json -Depth 3
                Invoke-RestMethod -Uri "$($Config.simaUrl.TrimEnd('/'))/api/agent/v1/commands/$key/result" -Method Post `
                    -Headers @{ Authorization = "Bearer $($Config.token)" } `
                    -Body ([Text.Encoding]::UTF8.GetBytes($body)) -ContentType 'application/json; charset=utf-8' -TimeoutSec 30 | Out-Null
                $done[$key].posted = $true     # "ok" maupun "ignored" (sudah dibatalkan/kedaluwarsa) sama-sama final
            } catch {
                Write-AgentLog "COMMAND #$key hasil belum terkirim: $($_.Exception.Message)"
            }
        }
    }

    # Simpan hanya 50 catatan terakhir.
    $keep = @($done.Keys | Sort-Object { [int]$_ } -Descending | Select-Object -First 50)
    $out = @{}
    foreach ($k in $keep) { $out[$k] = $done[$k] }
    $out | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $statePath -Encoding utf8
}

if (-not (Test-Path $configPath)) {
    Write-AgentLog "GAGAL: config.json tidak ditemukan di $root. Salin config.example.json menjadi config.json dan isi simaUrl + token."
    exit 1
}

try {
    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
} catch {
    Write-AgentLog "GAGAL: config.json tidak valid - $($_.Exception.Message)"
    exit 1
}

if (-not $config.simaUrl -or -not $config.token) {
    Write-AgentLog 'GAGAL: config.json harus berisi "simaUrl" dan "token".'
    exit 1
}

try {
    # --- Jaringan (IP dari adapter yang memegang rute default; cadangan: IP non-loopback pertama) ---
    $net = Get-NetworkInfo
    $ip = $net.ip
    if (-not $ip) {
        $ip = Get-Safely {
            (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
                Where-Object { $_.InterfaceAlias -notmatch 'Loopback' -and $_.IPAddress -notlike '169.254.*' } |
                Select-Object -First 1).IPAddress
        }
    }
    $battery = Get-BatteryInfo
    $diskInfo = Get-DiskInfo
    $cpuInfo = Get-CpuInfo
    $mem = Get-MemoryInfo
    $localAdmins = Get-LocalAdminInfo
    $builtinAdmin = Get-BuiltinAdminEnabled
    $usbState = Get-UsbStorageState

    # --- OS & hardware dasar (Win32_ComputerSystem / Win32_BIOS / Win32_Processor / Win32_OperatingSystem) ---
    $cs = Get-Safely { Get-CimInstance Win32_ComputerSystem -ErrorAction Stop }
    $bios = Get-Safely { Get-CimInstance Win32_BIOS -ErrorAction Stop }
    $cpu = Get-Safely { (Get-CimInstance Win32_Processor -ErrorAction Stop | Select-Object -First 1).Name }
    $osInfo = Get-Safely { Get-CimInstance Win32_OperatingSystem -ErrorAction Stop }
    $disk = Get-Safely { Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'" -ErrorAction Stop }

    $lastBootAt = $null
    if ($osInfo -and $osInfo.LastBootUpTime) { $lastBootAt = $osInfo.LastBootUpTime.ToString('o') }

    # --- Kepatuhan IT ---
    # BitLocker: modul bawaan Windows Pro/Enterprise/Education; tidak ada di edisi Home -> 'unknown'.
    $bitlockerStatus = 'unknown'
    $bitlockerVolume = Get-Safely { Get-BitLockerVolume -MountPoint 'C:' -ErrorAction Stop }
    if ($bitlockerVolume) {
        if ($bitlockerVolume.ProtectionStatus -eq 'On') { $bitlockerStatus = 'on' }
        elseif ($bitlockerVolume.ProtectionStatus -eq 'Off') { $bitlockerStatus = 'off' }
    }

    # Windows Defender: tidak tersedia kalau antivirus pihak ketiga menggantikannya sepenuhnya.
    $defenderEnabled = $null
    $defenderSignatureAt = $null
    $mpStatus = Get-Safely { Get-MpComputerStatus -ErrorAction Stop }
    if ($mpStatus) {
        $defenderEnabled = [bool]$mpStatus.AntivirusEnabled
        if ($mpStatus.AntivirusSignatureLastUpdated) {
            $defenderSignatureAt = $mpStatus.AntivirusSignatureLastUpdated.ToString('o')
        }
    }

    # Update Windows terakhir: Get-HotFix tidak selalu mencatat semua jenis update (mis. feature update),
    # tapi cukup sebagai indikasi kasar "kapan terakhir di-patch" tanpa perlu hak admin tambahan.
    $lastPatchAt = $null
    $lastHotfix = Get-Safely {
        Get-HotFix -ErrorAction Stop | Where-Object { $_.InstalledOn } | Sort-Object InstalledOn -Descending | Select-Object -First 1
    }
    if ($lastHotfix -and $lastHotfix.InstalledOn) { $lastPatchAt = $lastHotfix.InstalledOn.ToString('o') }

    $body = @{
        hostname               = $env:COMPUTERNAME
        ip_address              = $ip
        os_version              = $osInfo.Caption
        os_build                = $osInfo.BuildNumber
        os_architecture         = $osInfo.OSArchitecture
        domain                  = $cs.Domain
        last_boot_at            = $lastBootAt
        manufacturer            = $cs.Manufacturer
        model                   = $cs.Model
        serial_number           = $bios.SerialNumber
        cpu                     = $cpu
        ram_total_bytes         = if ($cs.TotalPhysicalMemory) { [int64]$cs.TotalPhysicalMemory } else { $null }
        disk_free_bytes         = if ($disk) { [int64]$disk.FreeSpace } else { (Get-PSDrive -Name C -ErrorAction SilentlyContinue).Free }
        disk_total_bytes        = if ($disk) { [int64]$disk.Size } else { $null }
        bitlocker_status        = $bitlockerStatus
        defender_enabled        = $defenderEnabled
        defender_signature_at   = $defenderSignatureAt
        last_patch_at           = $lastPatchAt
        agent_version           = $AgentVersion
        network_type            = $net.type
        wifi_ssid               = $net.ssid
        mac_address             = $net.mac
        gateway                 = $net.gateway
        battery_present         = $battery.present
        battery_percent         = $battery.percent
        battery_charging        = $battery.charging
        battery_health_percent  = $battery.health
        disks                   = $diskInfo.logical
        physical_disks          = $diskInfo.physical
        reboot_pending          = (Get-RebootPending)
        helper_missing          = (-not (Test-HelperFile))
        cpu_manufacturer        = $cpuInfo.manufacturer
        cpu_cores               = $cpuInfo.cores
        cpu_logical             = $cpuInfo.logical
        cpu_max_mhz             = $cpuInfo.max_mhz
        cpu_current_mhz         = $cpuInfo.current_mhz
        cpu_load_percent        = $cpuInfo.load
        cpu_l2_kb               = $cpuInfo.l2_kb
        cpu_l3_kb               = $cpuInfo.l3_kb
        ram_free_bytes          = $mem.free
        ram_slots_total         = $mem.slots
        ram_max_bytes           = $mem.max
        ram_modules             = $mem.modules
    }
    if ($null -ne $localAdmins) { $body.local_admins = $localAdmins }
    if ($null -ne $builtinAdmin) { $body.builtin_admin_enabled = $builtinAdmin }
    if ($usbState) { $body.usb_storage = $usbState }
    $body = $body | ConvertTo-Json -Depth 6

    if ($DryRun) { $body; exit 0 }

    $uri = "$($config.simaUrl.TrimEnd('/'))/api/agent/v1/heartbeat"
    $response = Invoke-RestMethod -Uri $uri -Method Post `
        -Headers @{ Authorization = "Bearer $($config.token)" } `
        -Body ([Text.Encoding]::UTF8.GetBytes($body)) -ContentType 'application/json; charset=utf-8' -TimeoutSec 15

    Write-AgentLog "OK v$AgentVersion - hostname=$($env:COMPUTERNAME) ip=$ip -> $($response.status)"

    # Pembaruan otomatis: SESUDAH laporan terkirim, supaya update yang gagal tidak pernah menghalangi laporan.
    Complete-PendingUpdates -Root $root

    # Token program pembantu (pengumuman/chat di sesi pengguna): hanya ada di jawaban saat dibuat/dirotasi.
    if ($response.PSObject.Properties.Name -contains 'helper' -and $response.helper -and $response.helper.token) {
        try { Save-HelperToken -Helper $response.helper -SimaUrl $config.simaUrl }
        catch { Write-AgentLog "HELPER GAGAL menyimpan token: $($_.Exception.Message)" }
    }

    # Perintah dari IT (daftar tetap). Dijalankan sebelum cek update; hasilnya dilaporkan ke server.
    $pendingCommands = @()
    if ($response.PSObject.Properties.Name -contains 'commands') { $pendingCommands = @($response.commands) }
    if ($pendingCommands.Count -gt 0 -or (Test-Path -LiteralPath (Join-Path $root 'agent-commands.json'))) {
        try { Invoke-AgentCommands -Commands $pendingCommands -Root $root -Config $config }
        catch { Write-AgentLog "COMMAND GAGAL diproses: $($_.Exception.Message)" }
    }
    $latest = 0
    if ($response.PSObject.Properties.Name -contains 'latest_agent_version') { $latest = [int]$response.latest_agent_version }
    Invoke-AgentUpdate -Root $root -SimaUrl $config.simaUrl -Token $config.token -LatestVersion $latest

    # Inventaris harian (software + Windows Update tertunda): terakhir, karena pemeriksaan update bisa makan waktu.
    [void](Send-Inventory -Root $root -Config $config)
} catch {
    Write-AgentLog "GAGAL kirim heartbeat: $($_.Exception.Message)"
    # Tidak ada popup ke user — ini proses latar belakang terjadwal, wajar untuk diam secara teknis,
    # tapi jejaknya tetap tercatat di sini (bukan silent failure yang menyembunyikan semuanya).
}
