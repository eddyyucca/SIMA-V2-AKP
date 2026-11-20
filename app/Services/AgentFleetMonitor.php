<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Schema;

/**
 * Menyusun data pemantauan SELURUH perangkat yang memakai SIMA Asset Agent: daftar perangkat dengan peringatannya,
 * ringkasan armada, dan sebaran (OS, edisi, versi agent, RAM). Hanya baca.
 * Daftar dibatasi MAX_DEVICES supaya halaman tetap ringan.
 */
class AgentFleetMonitor
{
    public const MAX_DEVICES = 2000;

    public function __construct(private AgentAlertRules $rules, private AgentUpdateManifest $manifest)
    {
    }

    /**
     * @return array{devices: list<array<string, mixed>>, alerts: list<array<string, mixed>>, summary: array<string, mixed>, distributions: array<string, array<string, int>>, truncated: bool, latest_agent_version: ?int}
     */
    public function build(): array
    {
        $latest = $this->manifest->latestVersion();

        $assets = Asset::query()
            ->where(fn ($q) => $q->whereHas('agentHeartbeat')->orWhereHas('agentTokens', fn ($t) => $t->whereNull('revoked_at')))
            ->with(['agentHeartbeat' => fn ($q) => $q->select($this->heartbeatColumns()), 'modelRef', 'employee'])
            ->orderBy('asset_tag')
            ->limit(self::MAX_DEVICES + 1)->get();
        $truncated = $assets->count() > self::MAX_DEVICES;
        $assets = $assets->take(self::MAX_DEVICES);

        $devices = [];
        $alerts = [];
        foreach ($assets as $asset) {
            $hb = $asset->agentHeartbeat;
            $deviceAlerts = $this->rules->evaluate($asset, $hb, $latest);

            $ramUsed = ($hb && $hb->ram_total_bytes && $hb->ram_free_bytes !== null)
                ? (int) round((($hb->ram_total_bytes - $hb->ram_free_bytes) / $hb->ram_total_bytes) * 100)
                : null;

            $devices[] = [
                'asset' => $asset,
                'hb' => $hb,
                'assigned' => (string) ($asset->employee?->name ?? ''),
                'status' => $hb === null ? 'never_reported' : ($asset->agent_status ?: 'never_reported'),
                'alerts' => $deviceAlerts,
                'worst' => $deviceAlerts[0]['severity'] ?? null,
                'disk' => $hb ? $this->rules->worstDiskUsage($hb) : null,
                'ram_used' => $ramUsed,
            ];

            foreach ($deviceAlerts as $a) {
                $alerts[] = $a + ['asset' => $asset];
            }
        }

        usort($alerts, fn ($a, $b) => [AgentAlertRules::SEVERITY_RANK[$a['severity']], $a['asset']->asset_tag]
            <=> [AgentAlertRules::SEVERITY_RANK[$b['severity']], $b['asset']->asset_tag]);

        return [
            'devices' => $devices,
            'alerts' => $alerts,
            'summary' => $this->summary($devices),
            'distributions' => $this->distributions($devices),
            'truncated' => $truncated,
            'latest_agent_version' => $latest,
        ];
    }

    /** Semua kolom laporan KECUALI daftar software (belasan KB per laptop, tidak dipakai halaman ringkasan). @return list<string> */
    private function heartbeatColumns(): array
    {
        return array_values(array_diff(Schema::getColumnListing('asset_heartbeats'), ['software']));
    }

    /** @param list<array<string, mixed>> $devices */
    private function summary(array $devices): array
    {
        $count = fn (callable $f) => count(array_filter($devices, $f));
        $reporting = $count(fn ($d) => $d['hb'] !== null);
        $compliant = $count(fn ($d) => $d['hb'] !== null && ! in_array($d['worst'], [AgentAlertRules::CRITICAL, AgentAlertRules::WARNING], true));

        return [
            'total' => count($devices),
            'online' => $count(fn ($d) => $d['status'] === 'online'),
            'offline' => $count(fn ($d) => $d['status'] === 'offline'),
            'never_reported' => $count(fn ($d) => $d['status'] === 'never_reported'),
            'critical' => $count(fn ($d) => $d['worst'] === AgentAlertRules::CRITICAL),
            'warning' => $count(fn ($d) => $d['worst'] === AgentAlertRules::WARNING),
            'compliant' => $compliant,
            'compliance_percent' => $reporting > 0 ? (int) round($compliant / $reporting * 100) : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $devices
     * @return array<string, array<string, int>>
     */
    private function distributions(array $devices): array
    {
        $os = [];
        $edition = [];
        $agent = [];
        $ram = ['< 8 GB' => 0, '8 - 15 GB' => 0, '16 - 31 GB' => 0, '32 GB +' => 0];
        $gpu = [];

        foreach ($devices as $d) {
            $hb = $d['hb'];
            if (! $hb) continue;
            $osName = trim((string) $hb->os_version) !== '' ? trim((string) $hb->os_version) . ($hb->os_build ? ' (' . $hb->os_build . ')' : '') : '-';
            $os[$osName] = ($os[$osName] ?? 0) + 1;

            // Edisi Windows menentukan kebijakan apa yang bisa diterapkan (Home tidak bisa gabung domain/GPO).
            $editionKey = self::editionOf((string) $hb->os_version);
            $edition[$editionKey] = ($edition[$editionKey] ?? 0) + 1;

            $agentKey = $hb->agent_version ? 'v' . $hb->agent_version : '-';
            $agent[$agentKey] = ($agent[$agentKey] ?? 0) + 1;

            // satu hitungan per perangkat: kartu diskrit bila ada (itu yang menentukan kemampuan grafis), kalau tidak kartu pertama
            $gpus = collect($hb->gpus ?? []);
            if ($gpus->isNotEmpty()) {
                $main = $gpus->firstWhere('kind', 'discrete') ?? $gpus->first();
                $gpuName = trim(preg_replace('/\((R|TM)\)/i', '', (string) ($main['name'] ?? '-')));
                $gpu[$gpuName] = ($gpu[$gpuName] ?? 0) + 1;
            }

            if ($hb->ram_total_bytes) {
                $gb = $hb->ram_total_bytes / 1073741824;
                $ram[$gb < 7.5 ? '< 8 GB' : ($gb < 15 ? '8 - 15 GB' : ($gb < 30 ? '16 - 31 GB' : '32 GB +'))]++;
            }
        }

        arsort($os);
        arsort($edition);
        arsort($agent);
        arsort($gpu);

        return ['os' => $os, 'edition' => $edition, 'agent' => $agent, 'ram' => array_filter($ram), 'gpu' => array_slice($gpu, 0, 12, true)];
    }

    /** 'Home' | 'Pro' | 'Enterprise' | 'Education' | 'Server' | '-' dari teks seperti "Microsoft Windows 11 Home Single Language". */
    public static function editionOf(string $osVersion): string
    {
        return match (true) {
            $osVersion === '' => '-',
            (bool) preg_match('/\bServer\b/i', $osVersion) => 'Server',
            (bool) preg_match('/\bEnterprise\b/i', $osVersion) => 'Enterprise',
            (bool) preg_match('/\bEducation\b/i', $osVersion) => 'Education',
            (bool) preg_match('/\bPro(fessional)?\b/i', $osVersion) => 'Pro',
            (bool) preg_match('/\bHome\b/i', $osVersion) => 'Home',
            default => '-',
        };
    }
}
