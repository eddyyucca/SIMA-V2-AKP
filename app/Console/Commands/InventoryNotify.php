<?php

namespace App\Console\Commands;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\License;
use App\Models\Repair;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class InventoryNotify extends Command
{
    protected $signature = 'inventory:notify {kind : daily|weekly|monthly}';
    protected $description = 'Kirim notifikasi terjadwal (stok, lisensi, garansi, resign, perbaikan)';

    public function handle(NotificationService $n): int
    {
        match ($this->argument('kind')) {
            'daily' => $this->daily($n),
            'weekly' => $this->weekly($n),
            'monthly' => $this->monthly($n),
            default => $this->error('kind tidak dikenal'),
        };
        return self::SUCCESS;
    }

    private function daily(NotificationService $n): void
    {
        $low = collect()
            ->merge(Accessory::whereColumn('qty_available', '<=', 'min_qty')->pluck('name'))
            ->merge(Component::whereColumn('qty_available', '<=', 'min_qty')->pluck('name'))
            ->merge(Consumable::whereColumn('qty', '<=', 'min_qty')->pluck('name'));
        if ($low->isNotEmpty()) {
            $n->notify(['Staff IT'], 'Rekap stok ≤ minimum (' . $low->count() . ')', $low->take(15)->implode(', '));
        }

        foreach ([30, 7, 1] as $days) {
            $date = now()->addDays($days)->toDateString();
            $ls = License::whereDate('expires_at', $date)->pluck('name');
            if ($ls->isNotEmpty()) {
                $n->notify(['Admin', 'Staff IT'], "Lisensi expired {$days} hari lagi", $ls->implode(', '), 'danger');
            }
        }

        foreach ([30, 7, 1] as $days) {
            $r = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', now()->addDays($days)->toDateString())->pluck('asset_tag');
            if ($r->isNotEmpty()) {
                $n->notify(['Admin', 'Staff IT'], "Sewa aset berakhir {$days} hari lagi", $r->implode(', '), 'danger');
            }
        }
        $over = Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString())->pluck('asset_tag');
        if ($over->isNotEmpty()) {
            $n->notify(['Admin', 'Staff IT'], 'Sewa aset lewat jatuh tempo (belum dikembalikan)', $over->implode(', '), 'danger');
        }

        foreach (\App\Models\ArrivalPlan::active()->with(['items', 'location'])->get() as $pl) {
            $dl = $pl->days_left;
            $pr = $pl->progress();
            if (in_array($dl, [7, 1], true) && $pl->status !== 'ready') {
                $n->notify(['Staff IT'], "Kedatangan {$pl->name} {$dl} hari lagi, belum siap",
                    "Rencana {$pl->plan_no}: {$pr['ready']} dari {$pr['total']} barang siap. Tiba " . $pl->arrival_date->format('d/m/Y') . ' di ' . ($pl->location?->name ?? '-') . '.', 'danger');
            } elseif ($dl < 0) {
                $n->notify(['Staff IT'], "Kedatangan {$pl->name} lewat tanggal, belum diserahkan",
                    "Rencana {$pl->plan_no} seharusnya tiba " . $pl->arrival_date->format('d/m/Y') . '. Ubah tanggal, serahkan, atau batalkan rencana.', 'danger');
            }
        }

        $resigned = Employee::where('status', 'resigned')->get()->filter->holdsItems();
        if ($resigned->isNotEmpty()) {
            $n->notify(['Staff IT'], 'Karyawan resign masih memegang barang', $resigned->pluck('name')->implode(', '), 'danger');
        }

        $late = Repair::where('status', '!=', 'done')->where('reported_at', '<', now()->subDays(14))->with('asset')->get();
        if ($late->isNotEmpty()) {
            $n->notify(['Staff IT'], 'Perbaikan > 14 hari belum selesai', $late->map(fn ($r) => $r->asset?->asset_tag)->implode(', '));
        }
    }

    private function weekly(NotificationService $n): void
    {
        $a = Asset::whereBetween('warranty_until', [now()->toDateString(), now()->addDays(30)->toDateString()])->pluck('asset_tag');
        if ($a->isNotEmpty()) {
            $n->notify(['Staff IT'], 'Garansi aset habis ≤ 30 hari (' . $a->count() . ')', $a->take(20)->implode(', '));
        }
    }

    private function monthly(NotificationService $n): void
    {
        $body = sprintf(
            'Total aset %d, dipakai %d, tersedia %d, dalam perbaikan %d. Perbaikan bulan lalu: Rp %s.',
            Asset::count(), Asset::where('status', 'deployed')->count(), Asset::where('status', 'available')->count(),
            Asset::where('status', 'repair')->count(),
            number_format(Repair::whereBetween('finished_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->sum('cost'), 0, ',', '.')
        );
        $n->notify(['Admin'], 'Ringkasan bulanan inventaris IT', $body, 'info');
    }
}
