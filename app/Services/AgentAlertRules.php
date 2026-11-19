<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetHeartbeat;
use Illuminate\Support\Carbon;

/**
 * Aturan peringatan untuk SIMA Asset Agent: dihitung langsung dari laporan terkini (tanpa tabel baru).
 * Peringatan hanyalah SINYAL untuk ditinjau manusia, bukan bukti pelanggaran dan bukan dasar tindakan otomatis.
 * Nilai dinamis yang berisik (beban CPU, RAM terpakai sesaat) sengaja tidak dijadikan aturan.
 */
class AgentAlertRules
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public const SEVERITY_RANK = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2];

    // Ambang (bisa dipindah ke pengaturan bila perlu)
    public const OFFLINE_WARNING_DAYS = 1;
    public const OFFLINE_CRITICAL_DAYS = 3;
    public const DISK_WARNING_PERCENT = 90;
    public const DISK_CRITICAL_PERCENT = 95;
    public const BATTERY_WARNING_PERCENT = 60;
    public const BATTERY_CRITICAL_PERCENT = 40;
    public const PENDING_UPDATES_WARNING = 5;
    public const DEFENDER_SIGNATURE_MAX_DAYS = 7;
    public const LAST_PATCH_MAX_DAYS = 60;
    public const LOW_RAM_GB = 8;

    /**
     * @return list<array{severity: string, code: string, params: array<string, mixed>}>
     */
    public function evaluate(Asset $asset, ?AssetHeartbeat $hb, ?int $latestAgentVersion = null): array
    {
        $alerts = [];
        $add = function (string $severity, string $code, array $params = []) use (&$alerts) {
            $alerts[] = ['severity' => $severity, 'code' => $code, 'params' => $params];
        };

        if ($hb === null) {
            $add(self::INFO, 'never_reported');

            return $alerts;
        }

        // Offline: sebentar (malam hari, laptop dimatikan) wajar dan cukup terlihat dari lencana status.
        if ($asset->agent_status === 'offline' && $asset->agent_last_seen) {
            $days = (int) floor(Carbon::parse($asset->agent_last_seen)->diffInDays(now(), true));
            if ($days >= self::OFFLINE_CRITICAL_DAYS) {
                $add(self::CRITICAL, 'offline_long', ['days' => $days]);
            } elseif ($days >= self::OFFLINE_WARNING_DAYS) {
                $add(self::WARNING, 'offline', ['days' => $days]);
            }
        }

        // Identitas: serial dilaporkan agent tidak sama dengan yang tercatat (indikasi perangkat ditukar).
        $hb->setRelation('asset', $asset);
        if ($hb->serialMismatchesAsset()) {
            $add(self::CRITICAL, 'serial_mismatch', ['serial' => $hb->serial_number, 'recorded' => $asset->serial_number]);
        }

        // Keamanan
        if ($hb->defender_enabled === false) {
            $add(self::CRITICAL, 'defender_off');
        } elseif ($hb->defender_signature_at
            && Carbon::parse($hb->defender_signature_at)->lt(now()->subDays(self::DEFENDER_SIGNATURE_MAX_DAYS))) {
            $add(self::WARNING, 'defender_outdated', ['days' => (int) floor(Carbon::parse($hb->defender_signature_at)->diffInDays(now(), true))]);
        }
        if ($hb->bitlocker_status === 'off') {
            $add(self::WARNING, 'bitlocker_off');
        }

        // Update Windows
        if (($hb->pending_updates_count ?? 0) >= self::PENDING_UPDATES_WARNING) {
            $add(self::WARNING, 'updates_pending', ['count' => $hb->pending_updates_count]);
        }
        if ($hb->last_patch_at && Carbon::parse($hb->last_patch_at)->lt(now()->subDays(self::LAST_PATCH_MAX_DAYS))) {
            $add(self::WARNING, 'patch_old', ['days' => (int) floor(Carbon::parse($hb->last_patch_at)->diffInDays(now(), true))]);
        }
        if ($hb->reboot_pending) {
            $add(self::INFO, 'reboot_pending');
        }

        // Penyimpanan
        $worst = $this->worstDiskUsage($hb);
        if ($worst !== null) {
            if ($worst['percent'] >= self::DISK_CRITICAL_PERCENT) {
                $add(self::CRITICAL, 'disk_full', $worst);
            } elseif ($worst['percent'] >= self::DISK_WARNING_PERCENT) {
                $add(self::WARNING, 'disk_almost_full', $worst);
            }
        }
        foreach (($hb->physical_disks ?? []) as $pd) {
            $health = $pd['health'] ?? 'unknown';
            if ($health === 'unhealthy') {
                $add(self::CRITICAL, 'disk_unhealthy', ['name' => $pd['name'] ?? '']);
            } elseif ($health === 'warning') {
                $add(self::WARNING, 'disk_warning', ['name' => $pd['name'] ?? '']);
            }
        }

        // Baterai
        if ($hb->battery_present && $hb->battery_health_percent !== null) {
            if ($hb->battery_health_percent < self::BATTERY_CRITICAL_PERCENT) {
                $add(self::CRITICAL, 'battery_bad', ['percent' => $hb->battery_health_percent]);
            } elseif ($hb->battery_health_percent < self::BATTERY_WARNING_PERCENT) {
                $add(self::WARNING, 'battery_worn', ['percent' => $hb->battery_health_percent]);
            }
        }

        // Kepatuhan hak akses dan USB (data HANYA-BACA dari agent v9+; laptop dengan agent lama dilewati)
        $outsiders = $this->nonAllowlistedAdmins($hb);
        if ($outsiders !== []) {
            $add(self::WARNING, 'local_admin_users', [
                'count' => count($outsiders),
                'names' => implode(', ', array_slice($outsiders, 0, 3)).(count($outsiders) > 3 ? ' +'.(count($outsiders) - 3) : ''),
            ]);
        }
        if ($hb->builtin_admin_enabled === true) {
            $add(self::WARNING, 'builtin_admin_enabled');
        }
        if (config('agent.require_usb_block') && $hb->usb_storage === 'allowed') {
            $add(self::WARNING, 'usb_open');
        }
        if (AgentFleetMonitor::editionOf((string) $hb->os_version) === 'Home') {
            $add(self::INFO, 'edition_home');
        }

        // Informasi
        if ($hb->ram_total_bytes && $hb->ram_total_bytes < self::LOW_RAM_GB * 1073741824 * 0.95) {
            $add(self::INFO, 'low_ram', ['gb' => round($hb->ram_total_bytes / 1073741824, 1)]);
        }
        if ($latestAgentVersion !== null && $hb->agent_version !== null && $hb->agent_version < $latestAgentVersion) {
            $add(self::INFO, 'agent_outdated', ['current' => $hb->agent_version, 'latest' => $latestAgentVersion]);
        }

        usort($alerts, fn ($a, $b) => self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']]);

        return $alerts;
    }

    /** Apakah akun ini termasuk daftar akun yang boleh menjadi administrator lokal (config agent.admin_allowlist)? */
    public static function isAllowedAdmin(string $account): bool
    {
        $account = mb_strtolower(trim($account));
        $short = mb_strtolower(trim((string) preg_replace('/^.*\\\\/', '', $account)));

        foreach ((array) config('agent.admin_allowlist', []) as $allowed) {
            $allowed = mb_strtolower(trim((string) $allowed));
            if ($allowed !== '' && ($allowed === $account || $allowed === $short)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> nama akun anggota Administrators lokal yang TIDAK ada di daftar yang diizinkan */
    public function nonAllowlistedAdmins(AssetHeartbeat $hb): array
    {
        $out = [];
        foreach (($hb->local_admins ?? []) as $m) {
            $name = trim((string) ($m['name'] ?? ''));
            if ($name !== '' && ! self::isAllowedAdmin($name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return array{drive: string, percent: int}|null */
    public function worstDiskUsage(AssetHeartbeat $hb): ?array
    {
        $worst = null;
        foreach (($hb->disks ?? []) as $d) {
            if (empty($d['total']) || ! isset($d['free'])) {
                continue;
            }
            $percent = (int) round((($d['total'] - $d['free']) / $d['total']) * 100);
            if ($worst === null || $percent > $worst['percent']) {
                $worst = ['drive' => (string) ($d['drive'] ?? '?'), 'percent' => $percent];
            }
        }

        if ($worst === null && $hb->disk_total_bytes && $hb->disk_free_bytes !== null) {
            $worst = ['drive' => 'C:', 'percent' => (int) round((($hb->disk_total_bytes - $hb->disk_free_bytes) / $hb->disk_total_bytes) * 100)];
        }

        return $worst;
    }
}
