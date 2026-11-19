<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use Notifiable, HasRoles, HasPushSubscriptions;

    /** Dipakai tampilan System Requisition (mis. daftar request menampilkan username & JSON detail). */
    protected $appends = ['username', 'role'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function employee() { return $this->hasOne(Employee::class); }

    /** Hanya akun yang aktif yang boleh masuk; akun yang dinonaktifkan ditolak di setiap permintaan (sesi yang sedang berjalan ikut terputus). */
    public function canAccessPanel(Panel $panel): bool { return (bool) $this->is_active; }

    public function isStaff(): bool { return $this->hasAnyRole(['Admin', 'Staff IT']); }

    /*
     * System Requisition punya tiga peran sendiri (user / admin / super_admin). Di aplikasi gabungan peran itu TIDAK disimpan
     * terpisah, melainkan diturunkan dari peran IT Asset: Admin -> super_admin, Staff IT -> admin, selain itu -> user.
     */
    public function getRoleAttribute(): string
    {
        return $this->hasRole('Admin') ? 'super_admin' : ($this->hasRole('Staff IT') ? 'admin' : 'user');
    }

    /** Username login = NIK karyawan; akun tanpa data karyawan menampilkan bagian email sebelum @. */
    public function getUsernameAttribute(): string { return $this->employee?->nik ?: Str::before((string) $this->email, '@'); }

    public function isSuperAdmin(): bool { return $this->role === 'super_admin'; }
    public function isAdmin(): bool { return $this->role !== 'user'; }
    public function canManageRequests(): bool { return $this->isAdmin(); }

    public function roleLabel(): string { return ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'user' => 'User'][$this->role]; }

    /** Tim IT penerima notifikasi request baru: akun aktif berperan Admin / Staff IT. */
    public function scopeRequisitionAdmins($query)
    {
        return $query->where('is_active', true)->whereHas('roles', fn ($r) => $r->whereIn('name', ['Admin', 'Staff IT']));
    }
}
