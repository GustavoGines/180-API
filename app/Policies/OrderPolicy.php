<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can manage orders.
     */
    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff']);
    }
}
