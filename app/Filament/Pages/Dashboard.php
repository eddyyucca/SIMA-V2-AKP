<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\License;
use App\Models\Repair;
use App\Models\StockMovement;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';
    protected string $view = 'filament.pages.dashboard';

    /** Pilihan kartu "Aset per Department": kategori aset ('' = semua) dan jenis nilai (purchase | book). */
    public string $deptCategory = '';
    public string $deptValue = 'purchase';

    /** Tahun kartu CAPEX & OPEX. */
    public int $spendYear = 0;

    public function updatedSpendYear(): void
    {
        $first = app(\App\Services\SpendService::class)->firstYear();
        if ($this->spendYear < $first || $this->spendYear > (int) now()->year) $this->spendYear = (int) now()->year;
    }

    public function updatedDeptCategory(): void
    {
        if ($this->deptCategory !== '' && ! Category::where('type', 'asset')->whereKey((int) $this->deptCategory)->exists()) $this->deptCategory = '';
    }

    public function updatedDeptValue(): void
    {
        if (! in_array($this->deptValue, ['purchase', 'book'], true)) $this->deptValue = 'purchase';
    }

    public function mount(): void
    {
        $this->spendYear = (int) now()->year;
        if (! auth()->user()->can('view inventory')) {
            // karyawan biasa tidak memakai dashboard IT Asset: langsung ke kartu System Requisition (di sana ada daftar aset yang dipakai)
            $this->redirect(route('requests.create'));
        }
    }

    public function getHeading(): string|Htmlable { return ''; }

    public function getMaxContentWidth(): Width|string|null { return Width::Full; }

    public function getWidgets(): array { return []; }

    private const DEPT_COLORS = ['#7c3aed', '#14b8a6', '#14628c', '#0ea5e9', '#374151', '#ec4899', '#ef4444', '#22c55e', '#6d28d9', '#29aae1', '#2563eb', '#84cc16', '#f97316', '#0891b2'];

    /**
     * Aset aktif per department pemegang (dengan filter kategori), jumlah dan nilai (perolehan / buku).
     * Aset yang belum dipegang atau pemegangnya tanpa department dipisah di baris abu-abu paling bawah.
     * Warna label nilai relatif terhadap yang terbesar: merah >= 25%, oranye >= 8%, hijau di bawahnya.
     */
    public function departmentData(): array
    {
        $q = Asset::active()->with('employee');
        if ($this->deptCategory !== '') $q->where('category_id', (int) $this->deptCategory);
        $dep = app(\App\Services\DepreciationService::class);
        $names = \App\Models\Department::orderBy('name')->pluck('name', 'id');

        $groups = [];
        foreach ($q->get() as $a) {
            $key = ! $a->employee_id ? 'none' : ($a->employee?->department_id ? (string) $a->employee->department_id : 'nodept');
            $val = $this->deptValue === 'book' ? $dep->bookValue($a, now()) : (float) $a->purchase_price;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            $groups[$key]['value'] = ($groups[$key]['value'] ?? 0) + $val;
        }

        $rows = collect($names)->map(fn ($n, $id) => ['key' => (string) $id, 'name' => $n, 'color' => self::DEPT_COLORS[$id % count(self::DEPT_COLORS)]] + ($groups[(string) $id] ?? ['count' => 0, 'value' => 0]))
            ->filter(fn ($r) => $r['count'] > 0)->values();
        foreach (['nodept' => 'Pemegang tanpa department', 'none' => 'Belum dipegang (stok gudang)'] as $k => $label) {
            if (isset($groups[$k])) $rows->push(['key' => $k, 'name' => $label, 'color' => '#9ca3af'] + $groups[$k]);
        }
        $max = max((float) $rows->max('value'), 0.0);
        $rows = $rows->map(function ($r) use ($max) {
            $ratio = $max > 0 ? $r['value'] / $max : 0;
            return $r + ['tone' => $ratio >= 0.25 ? 'red' : ($ratio >= 0.08 ? 'orange' : 'green')];
        });

        return ['rows' => $rows, 'count' => (int) $rows->sum('count'), 'value' => (float) $rows->sum('value'),
            'categories' => Category::where('type', 'asset')->orderBy('name')->pluck('name', 'id')];
    }

    /** Kartu CAPEX & OPEX: angka per bulan, rincian, dan perbandingan dengan tahun sebelumnya. */
    public function spendData(): array
    {
        $svc = app(\App\Services\SpendService::class);
        $year = $this->spendYear ?: (int) now()->year;
        $cur = $svc->year($year);
        $prev = $svc->year($year - 1);
        $delta = fn (float $now, float $before) => $before > 0 ? round(($now - $before) / $before * 100) : null;

        $max = max(1.0, (float) collect($cur['months'])->flatMap(fn ($m) => [$m['capex'], $m['opex']])->max());
        $exp = 10 ** floor(log10($max));
        $f = $max / $exp;
        $nice = ($f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10))) * $exp;

        $peak = fn (string $k) => collect($cur['months'])->map(fn ($m) => $m[$k])->filter(fn ($v) => $v > 0)->sortDesc()->keys()->first();
        return $cur + [
            'year' => $year, 'years' => range((int) now()->year, $svc->firstYear()),
            'axisMax' => $nice, 'ticks' => array_map(fn ($i) => $nice * $i / 4, range(0, 4)),
            'peakCapex' => $peak('capex'), 'peakOpex' => $peak('opex'),
            'deltaCapex' => $delta($cur['capex'], $prev['capex']), 'deltaOpex' => $delta($cur['opex'], $prev['opex']), 'deltaTotal' => $delta($cur['total'], $prev['total']),
            'prevYear' => $year - 1,
        ];
    }

    /** Semua angka dashboard dihitung di sini agar view tetap tipis. */
    public function data(): array
    {
        $live = Asset::active();
        $depr = app(\App\Services\DepreciationService::class)->totals(now());
        $total = (clone $live)->count();
        $deployed = Asset::where('status', 'deployed')->count();
        $available = Asset::where('status', 'available')->count();
        $repair = Asset::where('status', 'repair')->count();

        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $added = $months->map(fn (Carbon $m) => Asset::whereBetween('created_at', [$m, $m->copy()->endOfMonth()])->count())->all();
        $repairCost = $months->map(fn (Carbon $m) => (float) Repair::whereBetween('finished_at', [$m, $m->copy()->endOfMonth()])->sum('cost'))->all();
        $moves = $months->map(fn (Carbon $m) => StockMovement::where('type', 'out')->whereBetween('moved_at', [$m, $m->copy()->endOfMonth()])->count())->all();

        $lowStock = collect()
            ->merge(Accessory::whereColumn('qty_available', '<=', 'min_qty')->get()->map(fn ($i) => ['name' => $i->name, 'left' => $i->qty_available, 'min' => $i->min_qty, 'kind' => 'Accessory']))
            ->merge(Component::whereColumn('qty_available', '<=', 'min_qty')->get()->map(fn ($i) => ['name' => $i->name, 'left' => $i->qty_available, 'min' => $i->min_qty, 'kind' => 'Komponen']))
            ->merge(Consumable::whereColumn('qty', '<=', 'min_qty')->get()->map(fn ($i) => ['name' => $i->name, 'left' => (float) $i->qty, 'min' => (float) $i->min_qty, 'kind' => 'Consumable']));

        $activity = AssetAssignment::with(['asset', 'from', 'to'])->latest('assigned_at')->limit(8)->get()->map(fn ($a) => [
            'at' => $a->assigned_at,
            'icon' => $a->type,
            'title' => AssetAssignment::TYPES[$a->type] . ' · ' . ($a->asset?->asset_tag ?? '-'),
            'sub' => ($a->from?->name ?? 'Gudang IT') . ' → ' . ($a->to?->name ?? 'Gudang IT'),
            'url' => $a->asset ? AssetResource::getUrl('view', ['record' => $a->asset]) : null,
        ])->merge(StockMovement::with(['stockable', 'employee', 'department'])->where('type', 'out')->latest('moved_at')->limit(8)->get()->map(fn ($m) => [
            'at' => $m->moved_at,
            'icon' => 'stock',
            'title' => 'Stok keluar · ' . ($m->stockable?->name ?? '-'),
            'sub' => rtrim(rtrim(number_format((float) $m->qty, 2, ',', '.'), '0'), ',') . ' untuk ' . ($m->employee?->name ?? $m->department?->name ?? 'perangkat'),
            'url' => null,
        ]))->sortByDesc('at')->take(8)->values();

        return [
            'user' => auth()->user(),
            'total' => $total, 'deployed' => $deployed, 'available' => $available, 'repair' => $repair,
            'lost' => Asset::where('status', 'lost')->count(),
            'borrowed' => Asset::where('status', 'borrowed')->count(),
            'util' => $total ? round($deployed / $total * 100) : 0,
            'value' => (float) (clone $live)->sum('purchase_price'),
            'labels' => $months->map(fn ($m) => $m->translatedFormat('M'))->all(),
            'added' => $added, 'repairCost' => $repairCost, 'moves' => $moves,
            'byCategory' => (clone $live)->with('category')->get()->groupBy(fn ($a) => $a->category?->name ?? 'Lainnya')->map->count()->sortDesc(),
            'byLocation' => (clone $live)->with('location')->get()->groupBy(fn ($a) => $a->location?->name ?? 'Tanpa lokasi')->map->count()->sortDesc(),
            'lowStock' => $lowStock,
            'licenses' => License::whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(30)->toDateString())->orderBy('expires_at')->get(),
            'warranty' => Asset::whereBetween('warranty_until', [now()->toDateString(), now()->addDays(30)->toDateString()])->orderBy('warranty_until')->get(),
            'lateRepairs' => Repair::with('asset')->where('status', '!=', 'done')->where('reported_at', '<', now()->subDays(14))->get(),
            'resignedHolding' => Employee::resignedHolding(),
            'overSeats' => License::get()->filter(fn ($l) => $l->seats_used > $l->seats),
            'arrivals' => \App\Models\ArrivalPlan::active()->with(['items', 'location'])->whereDate('arrival_date', '<=', now()->addDays(14)->toDateString())->orderBy('arrival_date')->get(),
            'arrivalsAtRisk' => \App\Models\ArrivalPlan::whereIn('status', ['planned', 'prepared'])->with('items')->whereDate('arrival_date', '<=', now()->addDays(7)->toDateString())->orderBy('arrival_date')->get(),
            'book' => $depr['book'], 'depCost' => $depr['cost'], 'depAccumulated' => $depr['accumulated'], 'depYear' => $depr['year_expense'], 'depCount' => $depr['count'],
            'rentalCount' => Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->count(),
            'rentalMonthly' => (float) Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->sum('rental_monthly_cost'),
            'rentalExpiring' => Asset::where('ownership', 'rental')->where('status', '!=', 'returned')->whereDate('rental_end', '<=', now()->addDays(30)->toDateString())->orderBy('rental_end')->get(),
            'openRepairs' => Repair::where('status', '!=', 'done')->count(),
            'activity' => $activity,
            'canManage' => auth()->user()->can('manage inventory'),
        ];
    }
}
