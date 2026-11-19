<?php

namespace App\Services\Hris;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Str;

/** HRIS -> tabel employees. HRIS = sumber kebenaran; data lokal yang tidak ada di HRIS tidak pernah dihapus, hanya dilaporkan. */
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
            ?? ($h->nik_aliases ? Employee::withTrashed()->whereIn('nik', $h->nik_aliases)->first() : null);
        $deptId = $h->department ? (Department::whereRaw('lower(name) = ?', [mb_strtolower($h->department)])->value('id')
            ?? ($apply ? Department::create(['name' => $h->department])->id : null)) : null;

        $values = array_filter([
            'nik' => $h->employee_no, 'hris_id' => $h->id, 'name' => $h->name ?: null, 'department_id' => $deptId,
            'position' => $h->position, 'phone' => $h->phone,
        ], fn ($v) => $v !== null);
        $values['status'] = $h->is_active ? 'active' : 'resigned';

        if (! $emp) {
            if (! $apply) return ['employee' => null, 'action' => 'created'];
            $values['email'] = $this->freeEmail($h->email, $h->employee_no);
            $values['hris_synced_at'] = now();
            return ['employee' => Employee::create($values), 'action' => 'created'];
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

    /** Email wajib unik di tabel employees; email HRIS yang sudah dipakai orang lain / kosong diganti alamat turunan NIK. */
    private function freeEmail(?string $email, string $nik): string
    {
        if ($email && ! Employee::withTrashed()->where('email', $email)->exists()) return $email;
        return 'nik' . Str::slug($nik) . '@akp.test';
    }
}
