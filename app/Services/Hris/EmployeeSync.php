<?php

namespace App\Services\Hris;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Str;

/**
 * HRIS -> tabel employees/departments. HRIS adalah SATU-SATUNYA sumber nama, jabatan, department, section, divisi, status, dan kontak.
 * Karyawan lokal yang tidak ada di HRIS tidak pernah dihapus; ditandai hris_missing_at dan dilaporkan supaya HR/IT yang memutuskan.
 */
class EmployeeSync
{
    public function __construct(private EmployeeDirectory $directory) {}

    /**
     * Buat/perbarui satu karyawan dari baris HRIS. $apply=false hanya menghitung tanpa menyimpan.
     * @return array{employee:?Employee, action:string}  action: created|updated|unchanged
     */
    public function upsert(object $h, bool $apply = true): array
    {
        $emp = Employee::withTrashed()->where('nik', $h->employee_no)->first()
            ?? ($h->nik_aliases ? Employee::withTrashed()->whereIn('nik', $h->nik_aliases)->first() : null)
            // akun sisa SIMA tanpa NIK: cocokkan lewat email (hanya bila email itu milik tepat satu karyawan HRIS)
            ?? ($h->email ? Employee::withTrashed()->whereNull('nik')->whereRaw('lower(email) = ?', [mb_strtolower($h->email)])->first() : null);

        $deptId = $this->departmentId($h->department, $apply);
        $values = [
            'nik' => $h->employee_no, 'hris_id' => $h->id, 'name' => $h->name ?: null, 'department_id' => $deptId,
            'business_unit' => $h->division, 'section' => $h->section, 'position' => $h->position, 'phone' => $h->phone,
            'employment_status' => $h->employment_status, 'supervisor_nik' => $h->supervisor_nik,
        ];
        $values = array_filter($values, fn ($v) => $v !== null);
        $values['status'] = $h->is_active ? 'active' : 'resigned';
        $values['hris_missing_at'] = null;

        if (! $emp) {
            if (! $apply) return ['employee' => null, 'action' => 'created'];
            $values['email'] = $this->freeEmail($h->email, $h->employee_no, null);
            $values['hris_synced_at'] = now();
            return ['employee' => Employee::create($values), 'action' => 'created'];
        }

        // email dari HRIS ikut diperbarui selama tidak bentrok dengan karyawan lain (kolom email unik)
        if ($h->email && mb_strtolower($h->email) !== mb_strtolower((string) $emp->email)
            && ! Employee::withTrashed()->where('id', '!=', $emp->id)->whereRaw('lower(email) = ?', [mb_strtolower($h->email)])->exists()) {
            $values['email'] = $h->email;
        }

        $emp->fill($values);
        if (! $emp->isDirty()) {
            if ($apply) $emp->forceFill(['hris_synced_at' => now()])->saveQuietly();
            return ['employee' => $emp, 'action' => 'unchanged'];
        }
        if ($apply) {
            if ($emp->trashed() && $h->is_active) $emp->restore();
            $emp->hris_synced_at = now();
            $emp->save();   // status 'resigned' menonaktifkan akun loginnya lewat hook di model Employee
        }
        return ['employee' => $emp, 'action' => 'updated'];
    }

    /** Department dari HRIS: dicocokkan tanpa membedakan huruf besar/kecil, dibuat bila belum ada, dan ditandai berasal dari HRIS. */
    private function departmentId(?string $name, bool $apply): ?int
    {
        if (! $name) return null;
        $d = Department::withTrashed()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if (! $d) return $apply ? Department::create(['name' => $name, 'hris_synced_at' => now()])->id : null;
        if ($apply) {
            if ($d->trashed()) $d->restore();
            if (! $d->hris_synced_at || $d->hris_synced_at->lt(now()->subMinutes(30))) $d->forceFill(['hris_synced_at' => now()])->saveQuietly();
        }
        return $d->id;
    }

    /** Email wajib unik di tabel employees; email HRIS yang sudah dipakai orang lain / kosong diganti alamat turunan NIK. */
    private function freeEmail(?string $email, string $nik, ?int $exceptId): string
    {
        if ($email && ! Employee::withTrashed()->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) return $email;
        return 'nik' . Str::slug($nik) . '@akp.test';
    }
}
