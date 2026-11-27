<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pemohon request ITERA selalu karyawan yang tertaut ke akun loginnya (semua peran, termasuk Tim IT): tidak ada pilihan karyawan lain.
 * Akun peran User yang belum tertaut ke data karyawan tidak dapat mengajukan (daftar kosong). Hanya akun Tim IT yang belum tertaut
 * tidak dibatasi (null), supaya akun darurat tetap dapat bekerja.
 */
class RequesterScope
{
    public function allowedRequesters(User $u): ?Collection
    {
        if ($u->employee) return collect([$u->employee]);

        return $u->role === 'user' ? collect() : null;
    }
}
