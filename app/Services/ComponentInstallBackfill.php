<?php

namespace App\Services;

use App\Models\AccessoryHandover;
use App\Models\AccessoryHandoverItem;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen susulan untuk pemasangan / pelepasan komponen yang terjadi sebelum fitur dokumen pemasangan ada.
 * Hanya MEMBUAT dokumen (dan menautkannya ke pergerakan stok yang sudah ada): stok, spesifikasi aset, dan riwayat tidak diubah.
 * Aman dijalankan berulang: pergerakan yang sudah punya dokumen dilewati. Pergerakan satu aset dengan alasan sama yang berdekatan
 * waktunya (satu proses ganti komponen) digabung dalam satu dokumen. Tanggal dokumen = tanggal kejadian; pemegang = pemegang aset saat itu.
 */
class ComponentInstallBackfill
{
    /** Jarak maksimum antar pergerakan dalam satu dokumen (detik). */
    public const GAP_SECONDS = 120;

    /** Pergerakan pasang/lepas komponen yang belum punya dokumen, per kelompok (kronologis). */
    public function plan(): Collection
    {
        $linked = AccessoryHandoverItem::whereNotNull('stock_movement_id')->pluck('stock_movement_id')->all();
        $moves = StockMovement::where('stockable_type', Component::class)->whereIn('type', ['install', 'uninstall'])
            ->whereNotNull('asset_id')->whereNotIn('id', $linked)->orderBy('moved_at')->orderBy('id')->get();

        $groups = collect();
        foreach ($moves as $m) {
            $g = $groups->last();
            if ($g && $g['asset_id'] === $m->asset_id && $g['reason'] === (string) $m->reason && $m->moved_at->diffInSeconds($g['start'], true) <= self::GAP_SECONDS) {
                $groups[$groups->count() - 1]['moves']->push($m);
            } else {
                $groups->push(['asset_id' => $m->asset_id, 'reason' => (string) $m->reason, 'start' => $m->moved_at, 'moves' => collect([$m])]);
            }
        }
        return $groups;
    }

    /** Pemegang aset pada waktu tertentu menurut riwayat serah terima / mutasi / pengembalian. */
    public function holderAt(int $assetId, \Illuminate\Support\Carbon $at): ?int
    {
        $last = AssetAssignment::where('asset_id', $assetId)->where('assigned_at', '<=', $at)->orderByDesc('assigned_at')->orderByDesc('id')->first();
        return $last?->to_employee_id;
    }

    /** @return array{created:int,skipped:int,documents:list<string>} */
    public function run(bool $dryRun = false): array
    {
        $out = ['created' => 0, 'skipped' => 0, 'documents' => []];
        foreach ($this->plan() as $g) {
            $asset = Asset::withTrashed()->find($g['asset_id']);
            $moves = $g['moves'];
            if (! $asset || $moves->contains(fn ($m) => ! Component::withTrashed()->whereKey($m->stockable_id)->exists())) { $out['skipped']++; continue; }
            if ($dryRun) {
                $out['created']++;
                $out['documents'][] = $g['start']->format('d/m/Y') . ' ' . $asset->asset_tag . ' — ' . $moves->map(fn ($m) => ($m->type === 'install' ? 'pasang ' : 'lepas ') . (Component::withTrashed()->find($m->stockable_id)->name))->implode(', ');
                continue;
            }
            $h = DB::transaction(function () use ($g, $asset, $moves) {
                $at = $g['start'];
                $reason = isset(AccessoryHandover::INSTALL_REASONS[$g['reason']]) ? $g['reason'] : null;
                $h = AccessoryHandover::create([
                    'bast_number' => $this->number($at), 'type' => 'install', 'target_type' => 'asset', 'asset_id' => $asset->id,
                    'employee_id' => $this->holderAt($asset->id, $at), 'reason' => $reason, 'handed_at' => $at,
                    'notes' => 'Dokumen susulan: dibuat dari riwayat stok untuk pemasangan sebelum fitur dokumen pemasangan tersedia.'
                        . ($g['reason'] !== '' && ! $reason ? ' Alasan tercatat: ' . $g['reason'] . '.' : ''),
                    'created_by' => $moves->first()->created_by, 'meta' => ['backfilled' => true],
                ]);
                foreach ($moves as $m) {
                    $h->items()->create([
                        'stockable_type' => Component::class, 'stockable_id' => $m->stockable_id, 'qty' => $m->qty,
                        'action' => $m->type === 'install' ? 'install' : 'remove', 'condition' => $m->type === 'install' ? null : 'good', 'stock_movement_id' => $m->id,
                    ]);
                }
                return $h;
            });
            $out['created']++;
            $out['documents'][] = $h->bast_number . ' — ' . $asset->asset_tag . ' — ' . $h->fresh('items')->itemsSummary();
        }
        return $out;
    }

    /** Nomor dokumen sesuai tanggal kejadian (urutan per kode per tahun, tetap unik). */
    private function number(\Illuminate\Support\Carbon $at): string
    {
        return app(AccessoryHandoverService::class)->nextNumber('install', 'component', $at);
    }
}
