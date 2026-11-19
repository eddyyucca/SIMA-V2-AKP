<?php

namespace App\Reports;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\License;
use App\Models\Repair;
use App\Models\StockMovement;
use App\Models\StockOpnameItem;

class ReportCatalog
{
    public static function titles(): array
    {
        return [
            'assets' => ['title' => 'Daftar Aset', 'desc' => 'Filter kategori, lokasi, department, status, kondisi.'],
            'rentals' => ['title' => 'Aset Sewa', 'desc' => 'Vendor, kontrak, masa sewa, sisa hari, biaya per bulan, dan status pengembalian.'],
            'employee_items' => ['title' => 'Barang per Karyawan', 'desc' => 'Aset, accessories, dan lisensi yang dipegang (resigned ditandai).'],
            'asset_history' => ['title' => 'Riwayat Aset', 'desc' => 'Pemegang, mutasi, dan perbaikan per aset.'],
            'purchases' => ['title' => 'Riwayat Pembelian Stok', 'desc' => 'Tiap pembelian: tanggal, barang, vendor, no. PO, jumlah, harga satuan, total (filter tanggal).'],
            'stock' => ['title' => 'Stok', 'desc' => 'Posisi stok saat ini; centang "≤ minimum" untuk daftar belanja.'],
            'consumable_usage' => ['title' => 'Pemakaian Consumable', 'desc' => 'Per bulan dan per department beserta biaya (filter tanggal).'],
            'licenses' => ['title' => 'Lisensi', 'desc' => 'Seat terpakai, sisa seat, tanggal expired.'],
            'ip_addresses' => ['title' => 'List IP Device', 'desc' => 'Perangkat, IP, username, dan lokasi (password tidak disertakan).'],
            'warranty' => ['title' => 'Garansi', 'desc' => 'Aset dengan garansi aktif dan akan habis.'],
            'repairs' => ['title' => 'Perbaikan', 'desc' => 'Jumlah, biaya, dan durasi per periode.'],
            'disposals' => ['title' => 'Disposal', 'desc' => 'Aset yang dihapus beserta metode dan approval.'],
            'opname' => ['title' => 'Stock Opname', 'desc' => 'Hasil dan selisih seluruh sesi.'],
            'arrivals' => ['title' => 'Rencana Kedatangan Karyawan', 'desc' => 'Rencana onsite: tanggal tiba, status, kesiapan barang, dan daftar barang yang disiapkan (filter tanggal tiba).'],
            'depreciation' => ['title' => 'Nilai Aset per Tahun (Penyusutan)', 'desc' => 'Posisi 31 Desember tahun terpilih: harga perolehan, akumulasi, penyusutan tahun itu, dan nilai buku per aset.'],
            'depreciation_summary' => ['title' => 'Penyusutan per Kategori per Tahun', 'desc' => 'Beban penyusutan tiap tahun per kategori (jadwal ke depan sampai umur habis).'],
            'asset_value' => ['title' => 'Nilai Aset', 'desc' => 'Total nilai beli per kategori.'],
        ];
    }

    /** @return array{0:string,1:array,2:array} [title, head, rows] */
    public static function build(string $key, array $f): array
    {
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '-';
        $dt = fn ($d) => $d ? $d->format('d/m/Y H:i') : '-';
        $rp = fn ($v) => number_format((float) $v, 0, ',', '.');

        switch ($key) {
            case 'assets':
                $q = Asset::with(['category', 'employee.department', 'location']);
                foreach (['category_id', 'location_id', 'status', 'condition'] as $c) if (! empty($f[$c])) $q->where($c, $f[$c]);
                if (! empty($f['department_id'])) $q->whereHas('employee', fn ($e) => $e->where('department_id', $f['department_id']));
                return ['Daftar Aset', ['Kode', 'Kategori', 'Merek', 'Model', 'Serial', 'Pemegang', 'Department', 'Lokasi', 'Status', 'Kondisi', 'Tgl Beli', 'Harga', 'Garansi s/d', 'Kepemilikan'],
                    $q->orderBy('asset_tag')->get()->map(fn ($a) => [$a->asset_tag, $a->category?->name, $a->brand, $a->model, $a->serial_number, $a->employee?->name, $a->employee?->department?->name,
                        $a->location?->name, Asset::STATUSES[$a->status], Asset::CONDITIONS[$a->condition], $date($a->purchase_date), $rp($a->purchase_price), $date($a->warranty_until), Asset::OWNERSHIP[$a->ownership] ?? $a->ownership])->all()];

            case 'rentals':
                $rows = Asset::with(['vendor', 'employee'])->where('ownership', 'rental')->orderBy('rental_end')->get()->map(function ($a) use ($date, $rp) {
                    $months = ($a->rental_start && $a->rental_end) ? max(1, (int) round($a->rental_start->diffInMonths($a->rental_end))) : 0;
                    return [$a->asset_tag, trim("{$a->brand} {$a->model}"), $a->serial_number, $a->vendor?->name, $a->rental_contract_no, $date($a->rental_start), $date($a->rental_end),
                        $a->status === 'returned' ? '-' : $a->rental_days_left, $rp($a->rental_monthly_cost), $rp($a->rental_monthly_cost * $months), Asset::STATUSES[$a->status], $a->employee?->name];
                })->all();
                return ['Aset Sewa', ['Kode', 'Merek / Model', 'Serial', 'Vendor', 'No. Kontrak', 'Mulai', 'Berakhir', 'Sisa hari', 'Biaya/bulan (Rp)', 'Total kontrak (Rp)', 'Status', 'Pemegang'], $rows];

            case 'employee_items':
                $rows = [];
                foreach (Employee::with('department')->orderBy('name')->get() as $e) {
                    $flag = $e->status === 'resigned' ? 'RESIGNED' : '';
                    $who = [$e->nik, $e->name, $e->position, $e->department?->name];
                    foreach ($e->assets as $a) $rows[] = [...$who, $flag, 'Aset', "{$a->asset_tag} {$a->brand} {$a->model}", 1];
                    foreach ($e->heldAccessories() as $h) $rows[] = [...$who, $flag, 'Accessory', $h['accessory']?->name, $h['qty']];
                    foreach ($e->licenseSeats()->with('license')->get() as $s) $rows[] = [...$who, $flag, 'Lisensi', $s->license?->name, 1];
                }
                return ['Barang per Karyawan', ['NIK', 'Karyawan', 'Jabatan', 'Department', 'Status', 'Jenis', 'Barang', 'Jumlah'], $rows];

            case 'asset_history':
                $rows = [];
                foreach (AssetAssignment::with(['asset', 'from', 'to'])->orderBy('assigned_at')->get() as $a)
                    $rows[] = [$a->asset?->asset_tag, $dt($a->assigned_at), AssetAssignment::TYPES[$a->type], $a->from?->name ?? 'Gudang IT', $a->to?->name ?? 'Gudang IT', $a->bast_number];
                foreach (Repair::with('asset')->orderBy('reported_at')->get() as $r)
                    $rows[] = [$r->asset?->asset_tag, $dt($r->reported_at), 'Perbaikan', '-', '-', $r->issue . ' → ' . ($r->action ?: '-')];
                usort($rows, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
                return ['Riwayat Aset', ['Aset', 'Waktu', 'Kejadian', 'Dari', 'Kepada', 'Keterangan'], $rows];

            case 'stock':
                $rows = [];
                $low = ! empty($f['low']);
                foreach (Accessory::all() as $i) if (! $low || $i->isLow()) $rows[] = ['Accessory', $i->name, $i->qty_available, $i->qty_total, $i->min_qty, $i->isLow() ? 'BELI' : '', $rp($i->average_price), $rp($i->stock_value)];
                foreach (Component::all() as $i) if (! $low || $i->isLow()) $rows[] = ['Komponen', $i->name, $i->qty_available, $i->qty_total, $i->min_qty, $i->isLow() ? 'BELI' : '', $rp($i->average_price), $rp($i->stock_value)];
                foreach (Consumable::all() as $i) if (! $low || $i->isLow()) $rows[] = ['Consumable', $i->name . " ({$i->unit})", (float) $i->qty, (float) $i->qty, (float) $i->min_qty, $i->isLow() ? 'BELI' : '', $rp($i->average_price), $rp($i->stock_value)];
                return [$low ? 'Daftar Belanja (stok ≤ minimum)' : 'Posisi Stok', ['Jenis', 'Barang', 'Tersedia', 'Total', 'Minimum', 'Catatan', 'Harga rata-rata (Rp)', 'Nilai stok (Rp)'], $rows];

            case 'purchases':
                $q = \App\Models\StockReceipt::with(['stockable', 'vendor'])->orderBy('received_at')->orderBy('id');
                if (! empty($f['from'])) $q->whereDate('received_at', '>=', $f['from']);
                if (! empty($f['to'])) $q->whereDate('received_at', '<=', $f['to']);
                $kinds = [\App\Models\Accessory::class => 'Accessory', \App\Models\Consumable::class => 'Consumable', \App\Models\Component::class => 'Komponen'];
                $list = $q->get();
                $rows = $list->map(fn ($r) => [$date($r->received_at), $kinds[$r->stockable_type] ?? $r->stockable_type, $r->stockable?->name, $r->vendor?->name, $r->reference_no, (float) $r->qty, $rp($r->unit_price), $rp($r->total)])->all();
                $rows[] = ['TOTAL', '', count($rows) . ' pembelian', '', '', (float) $list->sum('qty'), '', $rp($list->sum(fn ($r) => $r->total))];
                return ['Riwayat Pembelian Stok', ['Tanggal', 'Jenis', 'Barang', 'Vendor', 'No. PO', 'Jumlah', 'Harga satuan (Rp)', 'Total (Rp)'], $rows];

            case 'consumable_usage':
                $q = StockMovement::with(['stockable', 'department', 'employee.department'])->where('stockable_type', Consumable::class)->where('type', 'out');
                if (! empty($f['from'])) $q->whereDate('moved_at', '>=', $f['from']);
                if (! empty($f['to'])) $q->whereDate('moved_at', '<=', $f['to']);
                $agg = [];
                foreach ($q->get() as $m) {
                    $dept = $m->department?->name ?? $m->employee?->department?->name ?? ($m->asset_id ? 'Perangkat' : '-');
                    $k = $m->moved_at->format('Y-m') . '|' . $dept . '|' . $m->stockable?->name;
                    $agg[$k] ??= [$m->moved_at->format('Y-m'), $dept, $m->stockable?->name, 0, 0];
                    $agg[$k][3] += (float) $m->qty;
                    $agg[$k][4] += (float) $m->qty * (float) ($m->stockable?->average_price ?? 0);
                }
                ksort($agg);
                return ['Pemakaian Consumable', ['Bulan', 'Department', 'Barang', 'Jumlah', 'Biaya (Rp)'], array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3], $rp($r[4])], array_values($agg))];

            case 'licenses':
                return ['Lisensi', ['Nama', 'Jenis', 'Seat', 'Terpakai', 'Sisa', 'Expired', 'Harga (Rp)', 'Vendor'],
                    License::with('vendor')->orderBy('expires_at')->get()->map(fn ($l) => [$l->name, License::TYPES[$l->type], $l->seats, $l->seats_used, $l->seats_free, $date($l->expires_at), $rp($l->price), $l->vendor?->name])->all()];

            case 'warranty':
                return ['Garansi Aset', ['Kode', 'Merek / Model', 'Pemegang', 'Garansi s/d', 'Sisa hari'],
                    Asset::with('employee')->whereNotNull('warranty_until')->whereDate('warranty_until', '>=', now())->orderBy('warranty_until')->get()
                        ->map(fn ($a) => [$a->asset_tag, trim("{$a->brand} {$a->model}"), $a->employee?->name, $date($a->warranty_until), (int) now()->startOfDay()->diffInDays($a->warranty_until, false)])->all()];

            case 'repairs':
                $q = Repair::with('asset');
                if (! empty($f['from'])) $q->whereDate('reported_at', '>=', $f['from']);
                if (! empty($f['to'])) $q->whereDate('reported_at', '<=', $f['to']);
                return ['Laporan Perbaikan', ['Aset', 'Dilaporkan', 'Kerusakan', 'Tindakan', 'Biaya (Rp)', 'Garansi', 'Status', 'Durasi (hari)'],
                    $q->orderBy('reported_at')->get()->map(fn ($r) => [$r->asset?->asset_tag, $dt($r->reported_at), $r->issue, $r->action, $rp($r->cost), $r->warranty_claim ? 'Klaim' : '-',
                        ['reported' => 'Dilaporkan', 'in_progress' => 'Diproses', 'done' => 'Selesai'][$r->status], (int) $r->reported_at->diffInDays($r->finished_at ?? now())])->all()];

            case 'disposals':
                return ['Disposal Aset', ['Aset', 'Alasan', 'Metode', 'Status', 'Pengaju', 'Disetujui oleh', 'Tanggal'],
                    Disposal::with(['asset', 'requester', 'approver'])->orderBy('created_at')->get()->map(fn ($d) => [$d->asset?->asset_tag, $d->reason, Disposal::METHODS[$d->method] ?? '-',
                        ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'][$d->status], $d->requester?->name, $d->approver?->name, $dt($d->approved_at)])->all()];

            case 'opname':
                $q = StockOpnameItem::with(['asset', 'opname.location'])->orderBy('stock_opname_id');
                if (! empty($f['opname'])) $q->where('stock_opname_id', $f['opname']);
                return ['Laporan Stock Opname', ['Sesi', 'Lokasi', 'Aset', 'Hasil', 'Kondisi', 'Catatan'],
                    $q->get()->sortBy(fn ($i) => [$i->stock_opname_id, $i->result === 'found' ? 1 : 0])->map(fn ($i) => ['#' . $i->stock_opname_id, $i->opname?->location?->name, $i->asset?->asset_tag . ' ' . $i->asset?->brand . ' ' . $i->asset?->model,
                        StockOpnameItem::RESULTS[$i->result], Asset::CONDITIONS[$i->condition] ?? '-', $i->notes])->values()->all()];

            case 'ip_addresses':
                $q = \App\Models\IpAddress::with('location')->orderBy('device_name');
                if (! empty($f['location_id'])) $q->where('location_id', $f['location_id']);
                return ['List IP Device', ['Nama Perangkat', 'IP Address', 'Username', 'Lokasi', 'Catatan'],
                    $q->get()->map(fn ($r) => [$r->device_name, $r->ip_address, $r->username ?: '-', $r->location?->name ?: '-', $r->notes ?: '-'])->all()];

            case 'arrivals':
                $q = \App\Models\ArrivalPlan::with(['items.asset', 'items.accessory', 'department', 'location', 'pic'])->orderBy('arrival_date');
                if (! empty($f['from'])) $q->whereDate('arrival_date', '>=', $f['from']);
                if (! empty($f['to'])) $q->whereDate('arrival_date', '<=', $f['to']);
                $rows = $q->get()->map(function ($pl) use ($date) {
                    $pr = $pl->progress();
                    return [$pl->plan_no, $pl->nik, $pl->name, $pl->department?->name, $pl->position, $pl->location?->name, $date($pl->arrival_date), \App\Models\ArrivalPlan::STATUSES[$pl->status],
                        $pr['ready'] . ' / ' . $pr['total'], $pl->pic?->name, $pl->items->where('status', '!=', 'cancelled')->map(fn ($i) => $i->label() . ' [' . \App\Models\ArrivalPlan::ITEM_STATUSES[$i->status] . ']')->implode('; ')];
                })->all();
                return ['Rencana Kedatangan Karyawan', ['No. Rencana', 'NIK', 'Karyawan', 'Department', 'Jabatan', 'Lokasi onsite', 'Tanggal tiba', 'Status', 'Barang siap', 'PIC IT', 'Daftar barang'], $rows];

            case 'depreciation':
                $year = (int) ($f['year'] ?? now()->year);
                $asOf = \Illuminate\Support\Carbon::create($year, 12, 31);
                $dep = app(\App\Services\DepreciationService::class);
                $q = Asset::with('category')->where('ownership', 'owned');
                foreach (['category_id', 'location_id'] as $c) if (! empty($f[$c])) $q->where($c, $f[$c]);
                if (! empty($f['department_id'])) $q->whereHas('employee', fn ($e) => $e->where('department_id', $f['department_id']));
                $rows = [];
                $t = ['n' => 0, 'cost' => 0, 'acc' => 0, 'exp' => 0, 'book' => 0];
                foreach ($q->orderBy('asset_tag')->get() as $a) {
                    $r = $dep->yearRow($a, $year);
                    $p = $r['profile'];
                    $rows[] = [$a->asset_tag, $a->category?->name, trim("{$a->brand} {$a->model}"), $date($a->purchase_date), $rp($p['cost']),
                        $p['depreciable'] ? $p['years'] : '-', $p['depreciable'] ? rtrim(rtrim(number_format($p['residual_percent'], 2), '0'), '.') . '%' : '-',
                        $rp($p['depreciable'] ? $p['per_year'] : 0), $rp($r['accumulated']), $rp($r['expense']), $rp($r['book']), $r['status']];
                    $t['exp'] += $r['expense'];
                    if ($r['held']) { $t['n']++; $t['cost'] += $p['cost']; $t['acc'] += $r['accumulated']; $t['book'] += $r['book']; }
                }
                $rows[] = ["TOTAL (aset dimiliki per 31 Des {$year}: {$t['n']})", '', '', '', $rp($t['cost']), '', '', '', $rp($t['acc']), $rp($t['exp']), $rp($t['book']), ''];
                return ["Nilai Aset per Tahun — posisi 31 Desember {$year}", ['Kode', 'Kategori', 'Merek / Model', 'Tgl Beli', 'Harga perolehan (Rp)', 'Umur (th)', 'Residu', 'Susut / tahun (Rp)',
                    "Akumulasi s/d 31 Des {$year} (Rp)", "Susut tahun {$year} (Rp)", "Nilai buku 31 Des {$year} (Rp)", 'Status'], $rows];

            case 'depreciation_summary':
                $dep = app(\App\Services\DepreciationService::class);
                $agg = $dep->byCategoryByYear(Asset::with('category')->where('ownership', 'owned')->get());
                $rows = [];
                $colTotals = array_fill_keys($agg['years'], 0);
                $totCost = 0; $totN = 0; $totAll = 0;
                foreach ($agg['rows'] as $name => $d) {
                    $line = [$name, $d['count'], $rp($d['cost'])];
                    $sum = 0;
                    foreach ($agg['years'] as $y) { $v = $d['years'][$y] ?? 0; $line[] = $rp($v); $colTotals[$y] += $v; $sum += $v; }
                    $line[] = $rp($sum);
                    $rows[] = $line;
                    $totCost += $d['cost']; $totN += $d['count']; $totAll += $sum;
                }
                $rows[] = array_merge(['TOTAL', $totN, $rp($totCost)], array_map(fn ($v) => $rp($v), array_values($colTotals)), [$rp($totAll)]);
                return ['Penyusutan per Kategori per Tahun', array_merge(['Kategori', 'Jumlah aset', 'Harga perolehan (Rp)'], array_map(fn ($y) => "Susut {$y} (Rp)", $agg['years']), ['Total (Rp)']), $rows];

            case 'asset_value':
                $rows = Asset::with('category')->active()->where('ownership', 'owned')->get()->groupBy(fn ($a) => $a->category?->name)
                    ->map(fn ($g, $cat) => [$cat, $g->count(), $rp($g->sum('purchase_price'))])->values()->all();
                $rental = Asset::active()->where('ownership', 'rental');
                $rows[] = ['ASET SEWA (biaya per bulan, bukan nilai beli)', (clone $rental)->count(), $rp((clone $rental)->sum('rental_monthly_cost'))];
                $rows[] = ['TOTAL NILAI BELI (milik sendiri)', Asset::active()->where('ownership', 'owned')->count(), $rp(Asset::active()->where('ownership', 'owned')->sum('purchase_price'))];
                return ['Nilai Aset (nilai beli)', ['Kategori', 'Jumlah', 'Total nilai beli (Rp)'], $rows];
        }
        abort(404);
    }
}
