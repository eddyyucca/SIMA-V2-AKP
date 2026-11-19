<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Berisi kredensial perangkat: hanya yang berhak mengelola inventaris (Admin, Staff IT). */
class IpAddressPolicy
{
    public function viewAny(User $u): bool { return $u->can('manage inventory'); }
    public function view(User $u, Model $m): bool { return $u->can('manage inventory'); }
    public function create(User $u, string $class = ''): bool { return $u->can('manage inventory'); }
    public function update(User $u, Model $m): bool { return $u->can('manage inventory'); }
    public function delete(User $u, Model $m): bool { return $u->can('delete data'); }
    public function restore(User $u, Model $m): bool { return $u->can('delete data'); }
    public function forceDelete(User $u, Model $m): bool { return false; }
    public function deleteAny(User $u): bool { return $u->can('delete data'); }
}
