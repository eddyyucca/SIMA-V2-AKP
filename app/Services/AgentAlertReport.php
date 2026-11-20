<?php

namespace App\Services;

/**
 * Laporan peringatan armada per TINGKAT: Kritis, Sedang (warning), dan Rendah (info). Dikelompokkan per jenis peringatan dengan
 * daftar perangkatnya, serta dapat diekspor ke CSV. Peringatan hanyalah sinyal untuk ditinjau, bukan bukti pelanggaran.
 */
class AgentAlertReport
{
    public const SEVERITIES = [AgentAlertRules::CRITICAL, AgentAlertRules::WARNING, AgentAlertRules::INFO];

    public function __construct(private AgentFleetMonitor $monitor)
    {
    }

    /** @return array{summary: array<string, array{items: int, devices: int, types: int}>, groups: array<string, list<array<string, mixed>>>, total_devices: int, alerts: list<array<string, mixed>>} */
    public function build(): array
    {
        $data = $this->monitor->build();
        $alerts = $data['alerts'];

        $groups = array_fill_keys(self::SEVERITIES, []);
        foreach ($alerts as $a) {
            $code = $a['code'];
            $sev = $a['severity'];
            $groups[$sev][$code]['code'] = $code;
            $groups[$sev][$code]['sample_params'] = $groups[$sev][$code]['sample_params'] ?? $a['params'];
            $groups[$sev][$code]['assets'][] = $a['asset'];
            $groups[$sev][$code]['items'][] = ['asset' => $a['asset'], 'params' => $a['params']];
        }

        $summary = [];
        foreach (self::SEVERITIES as $sev) {
            $types = $groups[$sev];
            uasort($types, fn ($x, $y) => count($y['assets']) <=> count($x['assets']));
            $groups[$sev] = array_values($types);
            $assetIds = [];
            $items = 0;
            foreach ($groups[$sev] as $g) {
                foreach ($g['assets'] as $asset) {
                    $assetIds[$asset->id] = true;
                    $items++;
                }
            }
            $summary[$sev] = ['items' => $items, 'devices' => count($assetIds), 'types' => count($groups[$sev])];
        }

        return ['summary' => $summary, 'groups' => $groups, 'total_devices' => count($data['devices']), 'alerts' => $alerts];
    }

    /**
     * Baris CSV (header + data) untuk satu tingkat atau semua. Nilai yang diawali = + - @ diberi apostrof di depan supaya tidak
     * dijalankan sebagai rumus di Excel (CSV injection), karena nama dan hostname berasal dari perangkat.
     *
     * @return list<list<string>>
     */
    public function csvRows(?string $severity = null): array
    {
        $rows = [['Tingkat', 'Kode', 'Pesan', 'Tag aset', 'Nama aset', 'Hostname', 'Lapor terakhir']];

        $data = $this->monitor->build();   // satu kali saja: kueri armada cukup berat
        $byAsset = [];
        foreach ($data['devices'] as $d) $byAsset[$d['asset']->id] = $d;

        foreach ($data['alerts'] as $a) {
            if ($severity !== null && $a['severity'] !== $severity) continue;
            $asset = $a['asset'];
            $hb = $byAsset[$asset->id]['hb'] ?? null;
            $rows[] = array_map([self::class, 'safe'], [
                trans('agent.sev_' . $a['severity']),
                $a['code'],
                trans('agent.alert_' . $a['code'], $a['params']),
                (string) $asset->asset_tag,
                self::assetName($asset),
                (string) ($hb?->hostname ?? ''),
                $asset->agent_last_seen ? $asset->agent_last_seen->toDateTimeString() : '',
            ]);
        }

        return $rows;
    }

    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /** Aset SIMA2 tidak punya kolom "nama": merek + model. */
    public static function assetName($asset): string
    {
        return trim(($asset->brand ?? '') . ' ' . ($asset->model ?? ''));
    }
}
