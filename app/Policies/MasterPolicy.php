<?php

namespace App\Policies;

use App\Models\User;

/** Master data murni (kategori, department, lokasi, vendor): hanya Admin yang menambah/mengubah. */
class MasterPolicy extends InventoryPolicy
{
    public function create(User $u, string $class = ''): bool { return $u->can('manage master'); }
}
