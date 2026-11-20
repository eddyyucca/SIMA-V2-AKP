<?php

namespace App\Services;

/**
 * Dashboard KEPATUHAN armada: edisi Windows (menentukan apakah kebijakan pusat bisa diterapkan), siapa yang punya hak admin lokal,
 * status penyimpanan USB, BitLocker, Defender, dan update. Seluruhnya berasal dari laporan agent (HANYA-BACA): SIMA tidak mengubah
 * apa pun di laptop; ini alat ukur untuk memutuskan dan membuktikan kebijakan yang diterapkan lewat Intune/GPO/manual.
 */
class AgentComplianceReport
{
    /** Edisi yang bisa dikelola kebijakan pusat (domain/GPO/Intune penuh). Home tidak. */
    public const MANAGEABLE_EDITIONS = ['Pro', 'Enterprise', 'Education', 'Server'];

    public function __construct(private AgentFleetMonitor $monitor, private AgentAlertRules $rules)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $devices = $this->monitor->build()['devices'];
        $reporting = array_values(array_filter($devices, fn ($d) => $d['hb'] !== null));

        $edition = [];
        $usb = ['blocked' => 0, 'write_blocked' => 0, 'allowed' => 0, 'unknown' => 0];
        $bitlocker = ['on' => 0, 'off' => 0, 'unknown' => 0];
        $defender = ['on' => 0, 'off' => 0, 'unknown' => 0];
        $adminData = 0;
        $adminExposed = [];
        $homeDevices = [];
        $cleanAdmins = 0;
        $builtinOn = 0;
        $pendingUpdates = 0;
        $usbData = 0;

        foreach ($reporting as $d) {
            $hb = $d['hb'];

            $ed = AgentFleetMonitor::editionOf((string) $hb->os_version);
            $edition[$ed] = ($edition[$ed] ?? 0) + 1;
            if ($ed === 'Home') {
                $homeDevices[] = $d;
            }

            if ($hb->usb_storage !== null) {
                $usbData++;
                $usb[$hb->usb_storage] = ($usb[$hb->usb_storage] ?? 0) + 1;
            }

            $bitlocker[in_array($hb->bitlocker_status, ['on', 'off'], true) ? $hb->bitlocker_status : 'unknown']++;
            $defender[$hb->defender_enabled === true ? 'on' : ($hb->defender_enabled === false ? 'off' : 'unknown')]++;

            if ($hb->local_admins !== null) {
                $adminData++;
                $outsiders = $this->rules->nonAllowlistedAdmins($hb);
                if ($outsiders !== []) {
                    $adminExposed[] = ['device' => $d, 'names' => $outsiders];
                } else {
                    $cleanAdmins++;
                }
            }
            if ($hb->builtin_admin_enabled === true) {
                $builtinOn++;
            }
            if (($hb->pending_updates_count ?? 0) >= AgentAlertRules::PENDING_UPDATES_WARNING) {
                $pendingUpdates++;
            }
        }

        usort($adminExposed, fn ($a, $b) => count($b['names']) <=> count($a['names']) ?: strcmp($a['device']['asset']->asset_tag, $b['device']['asset']->asset_tag));
        arsort($edition);

        $manageable = 0;
        foreach ($edition as $name => $n) {
            if (in_array($name, self::MANAGEABLE_EDITIONS, true)) {
                $manageable += $n;
            }
        }

        return [
            'reporting' => count($reporting),
            'edition' => $edition,
            'manageable' => $manageable,
            'home' => $edition['Home'] ?? 0,
            'home_devices' => $homeDevices,
            'usb' => $usb,
            'usb_data' => $usbData,
            'bitlocker' => $bitlocker,
            'defender' => $defender,
            'admin_data' => $adminData,
            'admin_exposed' => $adminExposed,
            'admin_clean' => $cleanAdmins,
            'builtin_admin_on' => $builtinOn,
            'pending_updates' => $pendingUpdates,
            'allowlist' => (array) config('agent.admin_allowlist', []),
            'require_usb_block' => (bool) config('agent.require_usb_block'),
        ];
    }
}
