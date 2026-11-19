<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Akses login karyawan. IT menentukan siapa yang boleh login: karyawan tanpa akses tetap tercatat sebagai pemegang aset.
 * Username = NIK karyawan dan password awal = NIK; pada login pertama karyawan wajib menggantinya (users.must_change_password).
 * Tidak ada email selamat datang; lupa password -> menghubungi tim IT (aksi "Reset password ke NIK").
 * Hanya Admin yang boleh memberi/mengubah peran selain "User" atau mengubah akun yang berperan khusus.
 */
class EmployeeAccountService
{
    public const ROLES = ['User' => 'User (karyawan biasa)', 'Staff IT' => 'Staff IT', 'Admin' => 'Admin'];

    /** Peran yang boleh diberikan oleh $actor. */
    public function assignableRoles(?User $actor): array
    {
        return $actor?->hasRole('Admin') ? self::ROLES : ['User' => self::ROLES['User']];
    }

    /** Boleh mengubah akun ini? Admin boleh semuanya; yang lain hanya akun yang berperan "User" saja. */
    public function mayManage(?User $actor, User $user): bool
    {
        return $actor?->hasRole('Admin') || $user->roles->pluck('name')->diff(['User'])->isEmpty();
    }

    /** Keadaan akun saat ini untuk mengisi formulir. */
    public function state(Employee $e): array
    {
        $u = $e->user;
        return ['has' => (bool) $u, 'active' => (bool) ($u?->is_active), 'role' => $u?->roles->pluck('name')->first() ?? 'User'];
    }

    /**
     * Terapkan pilihan IT. Dilewati bila tidak ada perubahan (supaya mengubah data karyawan lain tidak terhalang aturan peran).
     * @return array{user:?User,created:bool,changed:bool}
     */
    public function apply(Employee $e, bool $canLogin, ?string $role = null, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $role = $role ?: 'User';
        $cur = $this->state($e->loadMissing('user.roles'));
        $out = ['user' => $e->user, 'created' => false, 'changed' => false];

        $unchanged = $canLogin === $cur['active'] && (! $canLogin || $role === $cur['role'] || ! $cur['has']);
        if ($unchanged) return $out;

        if ($canLogin) {
            if (! $e->isActive()) throw new InvalidArgumentException('Karyawan berstatus resigned tidak bisa diberi akses login.');
            $this->assertLoginNik($e);
            if (! filter_var($e->email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email karyawan tidak valid; akun login memerlukan email yang valid.');
            if (! array_key_exists($role, $this->assignableRoles($actor))) throw new InvalidArgumentException("Anda tidak boleh memberi peran \"{$role}\". Hanya Admin yang bisa.");

            $user = DB::transaction(function () use ($e, $role, $actor, &$out) {
                $user = $e->user;
                if (! $user) {
                    $user = User::where('email', $e->email)->first();
                    if ($user && Employee::where('user_id', $user->id)->where('id', '!=', $e->id)->exists()) {
                        throw new InvalidArgumentException('Email ini sudah dipakai akun yang tertaut ke karyawan lain.');
                    }
                    if ($user && ! $this->mayManage($actor, $user)) {
                        throw new InvalidArgumentException('Sudah ada akun dengan email ini yang berperan khusus. Hanya Admin yang boleh menautkannya.');
                    }
                    if (! $user) {
                        $user = new User(['name' => $e->name, 'email' => $e->email, 'password' => $e->nik, 'is_active' => true, 'must_change_password' => true]);
                        $user->save();
                        $out['created'] = true;
                    }
                    $e->forceFill(['user_id' => $user->id])->saveQuietly();
                } elseif (! $this->mayManage($actor, $user)) {
                    throw new InvalidArgumentException('Akun ini berperan khusus. Hanya Admin yang boleh mengubah akses loginnya.');
                }
                $user->forceFill(['is_active' => true, 'name' => $e->name])->save();
                if ($user->email !== $e->email && ! User::where('email', $e->email)->where('id', '!=', $user->id)->exists()) $user->forceFill(['email' => $e->email])->save();
                if (! $user->hasRole($role) || $user->roles->count() !== 1) $user->syncRoles([$role]);
                return $user;
            });
            $out['user'] = $user; $out['changed'] = true;
        } elseif ($cur['has']) {
            $user = $e->user;
            if ($actor && $user->id === $actor->id) throw new InvalidArgumentException('Anda tidak bisa menonaktifkan akun Anda sendiri.');
            if (! $this->mayManage($actor, $user)) throw new InvalidArgumentException('Akun ini berperan khusus. Hanya Admin yang boleh menonaktifkannya.');
            $user->forceFill(['is_active' => false])->save();
            $out['changed'] = true;
        }
        return $out;
    }

    /** NIK adalah username login: wajib, minimal 6 karakter, tanpa spasi, dan unik. */
    public function assertLoginNik(Employee $e): void
    {
        $nik = (string) $e->nik;
        if ($nik === '') throw new InvalidArgumentException('NIK wajib diisi untuk karyawan yang boleh login (NIK dipakai sebagai username dan password awal).');
        if (mb_strlen($nik) < 6) throw new InvalidArgumentException('NIK minimal 6 karakter agar password awal tidak mudah ditebak.');
        if (! preg_match('/^[0-9]+$/', $nik)) throw new InvalidArgumentException('NIK hanya boleh berisi angka (tanpa huruf, spasi, atau tanda baca).');
        if (Employee::where('nik', $nik)->where('id', '!=', $e->id)->exists()) throw new InvalidArgumentException('NIK ini sudah dipakai karyawan lain.');
    }

    /** Reset password akun karyawan ke NIK; karyawan wajib menggantinya lagi saat login berikutnya. */
    public function resetPassword(Employee $e, ?User $actor = null): User
    {
        $actor ??= auth()->user();
        $user = $e->loadMissing('user.roles')->user;
        if (! $user) throw new InvalidArgumentException('Karyawan ini belum punya akun login.');
        if (! $this->mayManage($actor, $user)) throw new InvalidArgumentException('Akun ini berperan khusus. Hanya Admin yang boleh mereset passwordnya.');
        $this->assertLoginNik($e);
        $user->forceFill(['password' => $e->nik, 'must_change_password' => true])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();          // sesi lama ikut berakhir
        return $user;
    }
}
