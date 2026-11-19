<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

class InventoryPolicy
{
    private function master(Model $m): bool
    {
        return $m instanceof Category || $m instanceof Department || $m instanceof Location || $m instanceof Vendor
            || $m instanceof \App\Models\Employee || $m instanceof \App\Models\License;
    }

    public function viewAny(User $u): bool { return $u->can('view inventory'); }
    public function view(User $u, Model $m): bool { return $u->can('view inventory'); }

    public function create(User $u, string $class = ''): bool
    {
        if (in_array($class, [Category::class, Department::class, Location::class, Vendor::class], true)) {
            return $u->can('manage master');
        }
        return $u->can('manage inventory');
    }

    public function update(User $u, Model $m): bool
    {
        if ($m instanceof \App\Models\Disposal) return false; // lewat aksi approval
        return $this->master($m) && ! ($m instanceof \App\Models\Employee) && ! ($m instanceof \App\Models\License)
            ? $u->can('manage master')
            : $u->can('manage inventory');
    }

    public function delete(User $u, Model $m): bool { return $u->can('delete data'); }
    public function restore(User $u, Model $m): bool { return $u->can('delete data'); }
    public function forceDelete(User $u, Model $m): bool { return false; } // data tidak pernah dihapus permanen
    public function deleteAny(User $u): bool { return $u->can('delete data'); }
}
