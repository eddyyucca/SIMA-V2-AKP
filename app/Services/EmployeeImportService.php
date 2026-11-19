<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\Validator;

/**
 * Impor / ekspor karyawan. Impor bersifat "tambah atau perbarui": baris dicocokkan lewat NIK (bila diisi),
 * kalau tidak lewat email. Sel kosong pada baris yang cocok TIDAK menimpa data lama. Baris bermasalah dilewati dan dilaporkan.
 */
class EmployeeImportService
{
    public const HEAD = ['nik', 'nama', 'email', 'jabatan', 'department', 'telepon', 'status'];

    /** Alias judul kolom (judul file dinormalkan jadi huruf kecil bergaris bawah oleh pembaca Excel). */
    private const ALIASES = [
        'name' => 'nama', 'nama_lengkap' => 'nama', 'nama_karyawan' => 'nama',
        'position' => 'jabatan', 'dept' => 'department', 'departemen' => 'department',
        'phone' => 'telepon', 'telp' => 'telepon', 'no_hp' => 'telepon', 'hp' => 'telepon', 'no_telepon' => 'telepon',
        'e_mail' => 'email', 'nomor_induk_karyawan' => 'nik',
    ];

    public function exportRows(): array
    {
        return Employee::with('department')->orderBy('name')->get()->map(fn (Employee $e) => [
            (string) $e->nik, $e->name, $e->email, (string) $e->position, (string) $e->department?->name, (string) $e->phone,
            $e->status === 'active' ? 'aktif' : 'resigned',
        ])->all();
    }

    /** @return array{created:int,updated:int,errors:string[]} */
    public function import(array $rows, bool $createDepartments = false): array
    {
        $out = ['created' => 0, 'updated' => 0, 'errors' => []];
        $seenEmail = [];
        $seenNik = [];

        foreach ($rows as $i => $raw) {
            $n = $i + 2;                                                   // baris di file (1 = judul kolom)
            $r = $this->normalize($raw);
            if (collect($r)->every(fn ($v) => $v === null)) continue;      // baris kosong

            $err = $this->validateRow($r);
            if ($err) { $out['errors'][] = "Baris {$n}: {$err}"; continue; }

            $email = mb_strtolower($r['email']);
            if (isset($seenEmail[$email])) { $out['errors'][] = "Baris {$n}: email '{$email}' sama dengan baris {$seenEmail[$email]} di file ini."; continue; }
            if ($r['nik'] && isset($seenNik[mb_strtolower($r['nik'])])) { $out['errors'][] = "Baris {$n}: NIK '{$r['nik']}' sama dengan baris {$seenNik[mb_strtolower($r['nik'])]} di file ini."; continue; }

            // cocokkan dengan data yang ada: NIK dulu, kalau tidak ada baru email
            $target = $r['nik'] ? Employee::withTrashed()->where('nik', $r['nik'])->first() : null;
            $byEmail = Employee::withTrashed()->where('email', $email)->first();
            $target ??= $byEmail;

            if ($target && $byEmail && $byEmail->id !== $target->id) {
                $out['errors'][] = "Baris {$n}: NIK '{$r['nik']}' milik {$target->name}, tetapi email '{$email}' dipakai {$byEmail->name}."; continue;
            }
            if ($target && $r['nik'] && $target->nik && mb_strtolower($target->nik) !== mb_strtolower($r['nik'])) {
                $out['errors'][] = "Baris {$n}: email '{$email}' sudah dipakai {$target->name} dengan NIK berbeda ({$target->nik})."; continue;
            }
            if ($target?->trashed()) {
                $out['errors'][] = "Baris {$n}: {$target->name} sudah dihapus. Pulihkan dulu di menu Karyawan (filter Dihapus)."; continue;
            }

            $dept = null;
            if ($r['department']) {
                $dept = Department::whereRaw('LOWER(name) = ?', [mb_strtolower($r['department'])])->first();
                if (! $dept && ! $createDepartments) {
                    $out['errors'][] = "Baris {$n}: department '{$r['department']}' tidak dikenal (aktifkan \"Buat department baru otomatis\" atau tambahkan dulu)."; continue;
                }
            }

            try {
                $dept ??= $r['department'] ? Department::create(['name' => $r['department']]) : null;
                $data = array_filter([
                    'nik' => $r['nik'], 'name' => $r['nama'], 'email' => $email, 'position' => $r['jabatan'], 'phone' => $r['telepon'],
                    'department_id' => $dept?->id, 'status' => $r['status'],
                ], fn ($v) => $v !== null);

                if ($target) { $target->update($data); $out['updated']++; }
                else { Employee::create($data + ['status' => 'active']); $out['created']++; }
            } catch (\Throwable $e) {
                $out['errors'][] = "Baris {$n}: " . $e->getMessage(); continue;
            }
            $seenEmail[$email] = $n;
            if ($r['nik']) $seenNik[mb_strtolower($r['nik'])] = $n;
        }
        return $out;
    }

    /** Samakan judul kolom & rapikan nilai; sel kosong -> null. */
    private function normalize(array $raw): array
    {
        $row = array_fill_keys(self::HEAD, null);
        foreach ($raw as $k => $v) {
            $key = self::ALIASES[$k] ?? $k;
            if (! array_key_exists($key, $row)) continue;
            if (is_float($v) || is_int($v)) $v = sprintf('%.0f', $v);      // NIK / telepon yang terbaca sebagai angka oleh Excel
            $v = trim((string) $v);
            $row[$key] = $v === '' ? null : $v;
        }
        if ($row['status'] !== null) {
            $s = mb_strtolower($row['status']);
            $row['status'] = match (true) {
                in_array($s, ['aktif', 'active', 'a'], true) => 'active',
                in_array($s, ['resigned', 'resign', 'nonaktif', 'non-aktif', 'tidak aktif', 'keluar'], true) => 'resigned',
                default => '?',
            };
        }
        return $row;
    }

    private function validateRow(array $r): ?string
    {
        $v = Validator::make($r, [
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'nik' => ['nullable', 'max:50', 'regex:/^[0-9]+$/'],
            'jabatan' => ['nullable', 'max:100'],
            'telepon' => ['nullable', 'max:255'],
            'department' => ['nullable', 'max:255'],
            'status' => ['nullable', 'in:active,resigned'],
        ], [
            'nama.required' => 'nama wajib diisi.', 'email.required' => 'email wajib diisi.', 'email.email' => "email '{$r['email']}' tidak valid.",
            'nik.regex' => "NIK '{$r['nik']}' hanya boleh berisi angka (tanpa huruf, spasi, atau tanda baca).", 'nik.max' => 'NIK maksimal 50 karakter.',
            'status.in' => "status '" . ($r['status'] === '?' ? 'tidak dikenal' : $r['status']) . "' harus aktif atau resigned.",
        ]);
        return $v->fails() ? $v->errors()->first() : null;
    }
}
