<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetHeartbeat;
use App\Models\AssetSpecChange;

/**
 * Membandingkan laporan agent yang masuk dengan laporan TERAKHIR yang tersimpan, lalu mencatat perubahan
 * spesifikasi/identitas/OS/software ke asset_spec_changes.
 *
 * Aturan agar log tidak berisik atau menyesatkan:
 *  - laporan pertama (belum ada pembanding) tidak mencatat apa pun;
 *  - bidang yang sebelumnya kosong (mis. agent baru mulai mengirimnya) bukan perubahan;
 *  - daftar kosong (kegagalan baca di sisi agent) tidak dianggap "semua dicopot";
 *  - nilai dinamis (sisa disk, beban CPU, IP, baterai, uptime) TIDAK dicatat.
 */
class AssetSpecChangeRecorder
{
    public const MAX_ROWS_PER_ASSET = 500;
    private const MAX_SOFTWARE_ROWS_PER_REPORT = 100;

    /** bidang skalar yang dipantau => kategori */
    private const SCALARS = [
        'hostname' => 'identity',
        'domain' => 'identity',
        'manufacturer' => 'identity',
        'model' => 'identity',
        'serial_number' => 'identity',
        'cpu' => 'hardware',
        'cpu_cores' => 'hardware',
        'cpu_logical' => 'hardware',
        'ram_total_bytes' => 'hardware',
        'disk_total_bytes' => 'hardware',
        'os_version' => 'os',
        'os_build' => 'os',
    ];

    private const BYTE_FIELDS = ['ram_total_bytes', 'disk_total_bytes'];

    /**
     * @param  array<string, mixed>  $incoming  data laporan yang sudah tervalidasi
     * @return int jumlah perubahan yang dicatat
     */
    public function record(Asset $asset, ?AssetHeartbeat $previous, array $incoming): int
    {
        if ($previous === null) {
            return 0;
        }

        $rows = [];
        $add = function (string $category, string $field, ?string $old, ?string $new) use (&$rows) {
            $rows[] = ['category' => $category, 'field' => $field, 'old_value' => $old, 'new_value' => $new];
        };

        foreach (self::SCALARS as $field => $category) {
            if (! array_key_exists($field, $incoming) || $incoming[$field] === null) {
                continue;
            }
            $oldRaw = $previous->getAttribute($field);
            if ($oldRaw === null || $oldRaw === '') {
                continue;
            }
            $old = $this->scalar($field, $oldRaw);
            $new = $this->scalar($field, $incoming[$field]);
            if ($new !== '' && $old !== $new) {
                $add($category, $field, $old, $new);
            }
        }

        if (! empty($incoming['ram_modules']) && ! empty($previous->ram_modules)) {
            $old = $this->ramSummary($previous->ram_modules);
            $new = $this->ramSummary($incoming['ram_modules']);
            if ($old !== $new) {
                $add('hardware', 'ram_modules', $old, $new);
            }
        }

        if (! empty($incoming['physical_disks']) && ! empty($previous->physical_disks)) {
            $old = $this->diskSummary($previous->physical_disks);
            $new = $this->diskSummary($incoming['physical_disks']);
            if ($old !== $new) {
                $add('hardware', 'physical_disks', $old, $new);
            }
        }

        if (! empty($incoming['software']) && ! empty($previous->software)) {
            $this->diffSoftware($previous->software, $incoming['software'], $add);
        }

        if ($rows === []) {
            return 0;
        }

        $now = now();
        AssetSpecChange::insert(array_map(
            fn ($r) => $r + ['asset_id' => $asset->id, 'changed_at' => $now],
            $rows
        ));
        $this->prune($asset->id);

        return count($rows);
    }

    private function scalar(string $field, mixed $value): string
    {
        if (in_array($field, self::BYTE_FIELDS, true)) {
            return number_format(((float) $value) / 1073741824, 1, '.', '').' GB';
        }

        return trim((string) $value);
    }

    private function ramSummary(array $modules): string
    {
        $parts = [];
        foreach ($modules as $m) {
            $parts[] = trim(implode(' ', array_filter([
                isset($m['capacity']) ? number_format($m['capacity'] / 1073741824, 0, '.', '').' GB' : null,
                $m['type'] ?? null,
                isset($m['speed_mhz']) ? $m['speed_mhz'].' MHz' : null,
                ! empty($m['manufacturer']) ? '('.trim($m['manufacturer']).')' : null,
                ! empty($m['slot']) ? '@ '.trim($m['slot']) : null,
            ])));
        }
        sort($parts);

        return implode('; ', $parts);
    }

    private function diskSummary(array $disks): string
    {
        $parts = [];
        foreach ($disks as $d) {
            $parts[] = trim(implode(' ', array_filter([
                trim((string) ($d['name'] ?? '')),
                isset($d['size']) ? number_format($d['size'] / 1073741824, 0, '.', '').' GB' : null,
                $d['media'] ?? null,
            ])));
        }
        sort($parts);

        return implode('; ', $parts);
    }

    /** @param  callable(string,string,?string,?string):void  $add */
    private function diffSoftware(array $before, array $after, callable $add): void
    {
        $index = function (array $list): array {
            $map = [];
            foreach ($list as $s) {
                $name = trim((string) ($s['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $map[mb_strtolower($name)]['name'] = $name;
                $map[mb_strtolower($name)]['versions'][] = trim((string) ($s['version'] ?? ''));
            }
            foreach ($map as &$entry) {
                sort($entry['versions']);
                $entry['versions'] = implode(' / ', array_unique($entry['versions']));
            }
            unset($entry);

            return $map;
        };

        $old = $index($before);
        $new = $index($after);
        $label = fn (array $e) => trim($e['name'].' '.$e['versions']);

        $entries = [];
        foreach ($new as $key => $e) {
            if (! isset($old[$key])) {
                $entries[] = ['software_added', null, $label($e)];
            } elseif ($old[$key]['versions'] !== $e['versions']) {
                $entries[] = ['software_updated', $label($old[$key]), $label($e)];
            }
        }
        foreach ($old as $key => $e) {
            if (! isset($new[$key])) {
                $entries[] = ['software_removed', $label($e), null];
            }
        }

        $extra = count($entries) - self::MAX_SOFTWARE_ROWS_PER_REPORT;
        foreach (array_slice($entries, 0, self::MAX_SOFTWARE_ROWS_PER_REPORT) as [$field, $o, $n]) {
            $add('software', $field, $o, $n);
        }
        if ($extra > 0) {
            $add('software', 'software_more', null, (string) $extra);
        }
    }

    private function prune(int $assetId): void
    {
        $cutoff = AssetSpecChange::where('asset_id', $assetId)
            ->orderByDesc('id')
            ->skip(self::MAX_ROWS_PER_ASSET)
            ->take(1)
            ->value('id');

        if ($cutoff !== null) {
            AssetSpecChange::where('asset_id', $assetId)->where('id', '<=', $cutoff)->delete();
        }
    }
}
