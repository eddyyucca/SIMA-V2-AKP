<?php

namespace App\Policies;

use App\Models\ArrivalPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Rencana kedatangan hanya bisa diubah selama masih berjalan (belum diserahkan / dibatalkan). */
class ArrivalPlanPolicy extends InventoryPolicy
{
    public function update(User $u, Model $m): bool
    {
        return $m instanceof ArrivalPlan && $m->isActive() && $u->can('manage inventory');
    }
}
