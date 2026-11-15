<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        if ($user->company_id !== $order->company_id) {
            return false;
        }

        return $user->role === UserRole::ClientAdmin || $user->id === $order->user_id;
    }
}
