<?php

namespace App\Services;

use App\Models\AgentRolloutSnapshot;
use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Pencapaian rollout SIMA Asset Agent: dari semua LAPTOP yang tercatat (kategori di config('agent.laptop_categories')), berapa yang
 * sudah terpasang agent (pernah melapor), berapa yang baru dibuatkan token tetapi belum melapor, dan berapa yang belum tersentuh.
 * Aset yang sudah dihapus dari inventaris (status "disposed") tidak dihitung. Department dan section laptop = milik pemegangnya (HRIS).
 */
class AgentRolloutReport
{
    public const MISSING_LIST_LIMIT = 300;
    public const TREND_DAYS = 45;

    /** Laptop yang menjadi penyebut pencapaian. */
    public function laptopQuery()
    {
        $names = array_map('mb_strtoupper', (array) config('agent.laptop_categories', ['LAPTOP']));

        return Asset::query()
            ->whereHas('category', fn ($q) => $q->whereIn(\DB::raw('upper(name)'), $names ?: ['LAPTOP']))
            ->where('status', '!=', 'disposed');
    }

    /** @return array<string, mixed> */
    public function build(bool $withTrend = true): array
    {
        $laptops = $this->laptopQuery()
            ->with(['location:id,name', 'agentHeartbeat:id,asset_id,reported_at', 'employee.department:id,name'])
            ->withCount(['agentTokens as active_tokens' => fn ($q) => $q->whereNull('revoked_at')])
            ->get();

        $onlineSince = now()->subMinutes((int) config('agent.online_minutes', 15));
        $none = trans('agent.rollout_org_none');

        $rows = $laptops->map(function (Asset $a) use ($onlineSince, $none) {
            $installed = $a->agentHeartbeat !== null;
            $e = $a->employee;
            $department = $e?->department?->name ?: $none;

            return [
                'asset' => $a,
                'state' => $installed ? 'installed' : ($a->active_tokens > 0 ? 'token_only' : 'missing'),
                'online' => $installed && $a->agent_last_seen && $a->agent_last_seen->gte($onlineSince),
                'location' => $a->location?->name ?: '-',
                'status' => Asset::STATUSES[$a->status] ?? (string) $a->status,
                'department' => $department,
                // Nama section bisa sama di departemen berbeda: tampilkan bersama departemennya.
                'section' => $e && filled($e->section) ? $department . ' › ' . $e->section : $none,
                'user' => (string) ($e?->name ?? ''),
            ];
        });

        $total = $rows->count();
        $installed = $rows->where('state', 'installed')->count();
        $tokenOnly = $rows->where('state', 'token_only')->count();
        $missing = $rows->where('state', 'missing')->count();
        $online = $rows->where('online', true)->count();

        $pending = $rows->where('state', '!=', 'installed')->sortBy(fn ($r) => [$r['state'] === 'token_only' ? 0 : 1, $r['asset']->asset_tag])->values();

        return [
            'total' => $total,
            'installed' => $installed,
            'token_only' => $tokenOnly,
            'missing' => $missing,
            'online' => $online,
            'percent_installed' => $total > 0 ? round($installed / $total * 100, 1) : 0.0,
            'percent_online' => $total > 0 ? round($online / $total * 100, 1) : 0.0,
            'by_location' => $this->breakdown($rows, 'location'),
            'by_status' => $this->breakdown($rows, 'status'),
            'by_department' => $this->breakdown($rows, 'department', 50),
            'by_section' => $this->breakdown($rows, 'section', 50),
            'pending' => $pending->take(self::MISSING_LIST_LIMIT),
            'pending_total' => $pending->count(),
            'pending_all' => $pending,
            'non_laptop_agents' => $this->nonLaptopAgents($laptops->pluck('id')),
            'trend' => $withTrend ? $this->trend($total, $installed, $online) : [],
        ];
    }

    /** @return list<array{name: string, total: int, installed: int, percent: float}> */
    private function breakdown(Collection $rows, string $key, int $limit = 20): array
    {
        return $rows->groupBy($key)->map(function (Collection $g, string $name) {
            $installed = $g->where('state', 'installed')->count();

            return ['name' => $name, 'total' => $g->count(), 'installed' => $installed, 'percent' => round($installed / max(1, $g->count()) * 100, 1)];
        })->sortByDesc('total')->values()->take($limit)->all();
    }

    /** Aset BUKAN laptop yang sudah memakai agent (mis. PC desktop): informasi pelengkap. */
    private function nonLaptopAgents(Collection $laptopIds): int
    {
        return Asset::query()->whereHas('agentHeartbeat')->whereNotIn('assets.id', $laptopIds)->count();
    }

    /**
     * Tren harian dari snapshot, ditutup dengan angka hari ini (live) supaya grafik selalu berakhir di keadaan sekarang.
     *
     * @return list<array{date: string, total: int, installed: int, online: int}>
     */
    private function trend(int $total, int $installed, int $online): array
    {
        $points = AgentRolloutSnapshot::where('snapshot_date', '>=', now()->subDays(self::TREND_DAYS)->toDateString())
            ->where('snapshot_date', '<', now()->toDateString())
            ->orderBy('snapshot_date')->get()
            ->map(fn ($s) => ['date' => $s->snapshot_date->toDateString(), 'total' => $s->laptops_total, 'installed' => $s->installed, 'online' => $s->online])
            ->all();

        $points[] = ['date' => now()->toDateString(), 'total' => $total, 'installed' => $installed, 'online' => $online];

        return $points;
    }

    /** Menyimpan foto hari ini (dipanggil oleh perintah terjadwal). Idempoten per hari. */
    public function snapshotToday(): AgentRolloutSnapshot
    {
        $r = $this->build(false);

        return AgentRolloutSnapshot::updateOrCreate(
            ['snapshot_date' => now()->toDateString()],
            ['laptops_total' => $r['total'], 'installed' => $r['installed'], 'online' => $r['online']]
        );
    }
}
