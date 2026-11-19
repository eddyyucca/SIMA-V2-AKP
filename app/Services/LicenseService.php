<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseSeat;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LicenseService
{
    public function assign(License $license, ?Employee $employee = null, ?Asset $asset = null): LicenseSeat
    {
        if (! $employee && ! $asset) {
            throw new InvalidArgumentException('Pilih karyawan atau aset penerima seat.');
        }
        if ($employee && ! $employee->isActive()) {
            throw new InvalidArgumentException('Karyawan berstatus resigned tidak bisa menerima seat.');
        }

        return DB::transaction(function () use ($license, $employee, $asset) {
            $locked = License::query()->lockForUpdate()->findOrFail($license->id);
            $used = $locked->activeSeats()->count();
            if ($used >= $locked->seats) {
                throw new InvalidArgumentException("Seat penuh ({$used}/{$locked->seats}). Alokasi ditolak.");
            }

            return $locked->seatRows()->create([
                'employee_id' => $employee?->id,
                'asset_id' => $asset?->id,
                'assigned_at' => now(),
            ]);
        });
    }

    public function revoke(LicenseSeat $seat): void
    {
        if (! $seat->revoked_at) {
            $seat->update(['revoked_at' => now()]);
        }
    }

    /** Peringatan jika seat aktif melebihi jumlah lisensi (mis. setelah seats diturunkan). */
    public function overAllocated(License $license): bool
    {
        return $license->activeSeats()->count() > $license->seats;
    }
}
