<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Apa saja yang dipakai seorang pengguna: aset yang dipegang, accessories, seat lisensi, dan peminjaman aset (System Requisition)
 * yang masih berjalan. Dipakai kartu "Aset yang Anda Pakai" di halaman System Requisition.
 */
class MyItemsService
{
    /**
     * Data karyawan untuk mengisi otomatis formulir System Requisition (nama, NIK, jabatan, department).
     * Nama hanya diisi bila sudah berbentuk "Nama Depan Nama Belakang" (aturan formulir SR); yang kosong dilewati.
     * @return array{name:?string,nik:?string,position:?string,department:?string}|null
     */
    public function profileFor(?User $user): ?array
    {
        $e = $user ? Employee::with('department')->where('user_id', $user->id)->first() : null;
        if (! $e) return null;
        $name = preg_match('/^[A-Z][a-z]+( [A-Z][a-z]+)*$/', (string) $e->name) ? $e->name : null;
        return ['name' => $name, 'nik' => $e->nik ?: null, 'position' => $e->position ?: null, 'department' => $e->department?->name];
    }

    /**
     * @return array{employee:?Employee,assets:Collection,accessories:Collection,licenses:Collection,loans:Collection,history:Collection}
     */
    public function forUser(?User $user): array
    {
        $empty = ['employee' => null, 'assets' => collect(), 'accessories' => collect(), 'licenses' => collect(), 'loans' => collect(), 'history' => collect()];
        if (! $user) return $empty;

        $loans = ITRequest::where('user_id', $user->id)->where('type', 'PEMINJAMAN_ASET')
            ->whereIn('status', ['Approved', 'In Progress'])->whereNull('returned_at')
            ->orderBy('loan_end_date')->get();

        $employee = Employee::with('department')->where('user_id', $user->id)->first();
        if (! $employee) return ['loans' => $loans] + $empty;

        // aset yang sedang dipegang (tidak termasuk yang sudah disposed / dikembalikan ke vendor)
        $assets = Asset::with(['category', 'location'])->where('employee_id', $employee->id)
            ->whereNotIn('status', Asset::INACTIVE)->orderBy('asset_tag')->get();

        // serah terima terakhir ke karyawan ini per aset: tanggal pegang & BAST
        $last = AssetAssignment::whereIn('asset_id', $assets->pluck('id'))->where('to_employee_id', $employee->id)
            ->orderByDesc('assigned_at')->orderByDesc('id')->get()->unique('asset_id')->keyBy('asset_id');
        $assets->each(fn (Asset $a) => $a->setRelation('lastHandover', $last->get($a->id)));

        // BAST serah terima terakhir untuk tiap accessories yang dipegang
        $handovers = \App\Models\AccessoryHandover::with('items')->where('employee_id', $employee->id)->where('type', 'checkout')->orderByDesc('handed_at')->orderByDesc('id')->get();
        $accessories = $employee->heldAccessories()->map(fn ($h) => $h + ['bast' => $handovers->first(fn ($x) => $x->items->contains('accessory_id', $h['accessory']?->id))]);

        return [
            'employee' => $employee,
            'assets' => $assets,
            'accessories' => $accessories,
            'licenses' => $employee->licenseSeats()->with('license')->get(),
            'loans' => $loans,
            'history' => $employee->accessoryHandovers()->with('items.stockable')->withCount('files')->limit(30)->get(),
        ];
    }
}
