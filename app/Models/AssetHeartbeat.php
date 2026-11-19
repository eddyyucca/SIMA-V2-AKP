<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laporan TERKINI (bukan log) dari SIMA Asset Agent terpasang di laptop/PC suatu aset: satu baris per aset,
 * ditimpa tiap heartbeat baru (lihat AssetAgentController::heartbeat()). Lihat docs/rencana-agent-monitoring-aset.md.
 */
class AssetHeartbeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'hostname', 'ip_address', 'os_version', 'disk_free_bytes', 'agent_version', 'reported_at',
        // Hardware
        'manufacturer', 'model', 'serial_number', 'cpu', 'ram_total_bytes', 'disk_total_bytes',
        'os_build', 'os_architecture', 'domain', 'last_boot_at',
        // Kepatuhan IT
        'bitlocker_status', 'defender_enabled', 'defender_signature_at', 'last_patch_at',
        // Agent v4: jaringan, baterai, disk, Windows Update tertunda, software terpasang
        'network_type', 'wifi_ssid', 'mac_address', 'gateway',
        'battery_present', 'battery_percent', 'battery_charging', 'battery_health_percent',
        'disks', 'physical_disks',
        'reboot_pending', 'pending_updates_count', 'pending_updates', 'updates_checked_at',
        'software', 'software_count', 'software_reported_at',
        // Agent v5: detail prosesor dan memori
        'cpu_manufacturer', 'cpu_cores', 'cpu_logical', 'cpu_max_mhz', 'cpu_current_mhz',
        'cpu_load_percent', 'cpu_l2_kb', 'cpu_l3_kb',
        'ram_free_bytes', 'ram_slots_total', 'ram_max_bytes', 'ram_modules',
        // Agent v6: benchmark kecepatan baca/tulis (MB/s)
        'ram_read_mbps', 'ram_write_mbps', 'disk_read_mbps', 'disk_write_mbps', 'benchmark_at',
        // Agent v9: kepatuhan (hanya-baca)
        'local_admins', 'builtin_admin_enabled', 'usb_storage',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'last_boot_at' => 'datetime',
        'defender_enabled' => 'boolean',
        'defender_signature_at' => 'datetime',
        'last_patch_at' => 'datetime',
        'battery_present' => 'boolean',
        'battery_charging' => 'boolean',
        'reboot_pending' => 'boolean',
        'disks' => 'array',
        'physical_disks' => 'array',
        'pending_updates' => 'array',
        'updates_checked_at' => 'datetime',
        'software' => 'array',
        'software_reported_at' => 'datetime',
        'ram_modules' => 'array',
        'benchmark_at' => 'datetime',
        'local_admins' => 'array',
        'builtin_admin_enabled' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Serial yang dilaporkan agen tidak cocok dengan serial tercatat di SIMA (indikasi perangkat ditukar). */
    public function serialMismatchesAsset(): bool
    {
        $assetSerial = trim((string) $this->asset?->serial_number);
        $reported = trim((string) $this->serial_number);

        return $assetSerial !== '' && $reported !== '' && strcasecmp($assetSerial, $reported) !== 0;
    }
}
