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

        // karyawan lokal yang tidak ada di HRIS: dilaporkan, TIDAK dihapus/dinonaktifkan (keputusan manusia)
        $known = $all->keys()->merge($all->flatMap(fn ($h) => $h->nik_aliases))->all();
        $orphans = Employee::where('status', 'active')->whereNotNull('nik')->whereNotIn('nik', $known)->get(['nik', 'name']);

        // resign yang masih memegang aset: tim IT perlu menindaklanjuti
        $holding = Employee::where('status', 'resigned')->whereHas('assets', fn ($q) => $q->whereIn('status', ['deployed', 'borrowed']))->get(['nik', 'name']);

        $this->info(($apply ? 'DITERAPKAN' : 'SIMULASI (tambahkan --apply)') . ": baru {$count['created']}, diperbarui {$count['updated']}, tetap {$count['unchanged']}.");
        if ($orphans->isNotEmpty()) $this->warn("Aktif di sini tapi tidak ada di HRIS ({$orphans->count()}): " . $orphans->take(10)->map(fn ($e) => "{$e->nik} {$e->name}")->join(', '));
        if ($holding->isNotEmpty()) $this->warn("Resign tetapi masih memegang aset ({$holding->count()}): " . $holding->take(10)->map(fn ($e) => "{$e->nik} {$e->name}")->join(', '));

        return self::SUCCESS;
    }
}
