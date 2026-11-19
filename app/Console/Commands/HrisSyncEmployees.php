<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Hris\EmployeeDirectory;
use App\Services\Hris\EmployeeSync;
use Illuminate\Console\Command;

class HrisSyncEmployees extends Command
{
    protected $signature = 'hris:sync-employees {--apply : Simpan perubahan (tanpa ini hanya simulasi)}';
    protected $description = 'Sinkronkan karyawan dari HRIS (sumber kebenaran): tambah, perbarui, tandai resign. Tidak pernah menghapus.';

    public function handle(EmployeeDirectory $hris, EmployeeSync $sync): int
    {
        $hris->refresh();
        $all = $hris->all();
        if ($all === null || $all->isEmpty()) {
            $this->error('HRIS tidak bisa dibaca / kosong: ' . (EmployeeDirectory::$lastError ?? 'tidak ada data') . '. Tidak ada yang diubah.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $count = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($all as $h) $count[$sync->upsert($h, $apply)['action']]++;

        // karyawan lokal yang tidak ada di HRIS (sisa impor SIMA): ditandai hris_missing_at dan DILAPORKAN; tidak dihapus, tidak diubah statusnya (keputusan HR/IT)
        $known = $all->keys()->merge($all->flatMap(fn ($h) => $h->nik_aliases))->map(fn ($k) => (string) $k)->all();
        $missing = Employee::where(fn ($q) => $q->whereNull('nik')->orWhereNotIn('nik', $known))->get(['id', 'nik', 'name', 'status', 'hris_missing_at']);
        if ($apply && $missing->isNotEmpty()) Employee::whereIn('id', $missing->pluck('id'))->whereNull('hris_missing_at')->update(['hris_missing_at' => now()]);
        $orphans = $missing->where('status', 'active');
        $holdingMissing = $missing->filter(fn ($e) => Employee::find($e->id)?->assets()->whereIn('status', ['deployed', 'borrowed'])->exists());

        // resign yang masih memegang aset: tim IT perlu menindaklanjuti
        $holding = Employee::where('status', 'resigned')->whereHas('assets', fn ($q) => $q->whereIn('status', ['deployed', 'borrowed']))->get(['nik', 'name']);

        $this->info(($apply ? 'DITERAPKAN' : 'SIMULASI (tambahkan --apply)') . ": baru {$count['created']}, diperbarui {$count['updated']}, tetap {$count['unchanged']}, HRIS {$all->count()} karyawan.");
        if ($missing->isNotEmpty()) $this->warn("Tidak ditemukan di HRIS ({$missing->count()}; {$orphans->count()} berstatus aktif, {$holdingMissing->count()} memegang aset) - perlu diputuskan HR/IT: " . $missing->take(12)->map(fn ($e) => trim(($e->nik ?: '(tanpa NIK)') . ' ' . $e->name))->join(', ') . ($missing->count() > 12 ? ' ...' : ''));
        if ($holding->isNotEmpty()) $this->warn("Resign tetapi masih memegang aset ({$holding->count()}): " . $holding->take(10)->map(fn ($e) => "{$e->nik} {$e->name}")->join(', '));

        return self::SUCCESS;
    }
}
