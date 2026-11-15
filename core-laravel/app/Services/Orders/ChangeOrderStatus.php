<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeOrderStatus
{
    public function handle(Order $order, OrderStatus $to, ?User $by = null, ?string $comment = null): Order
    {
        $from = $order->status;

        if (! $from->canMoveTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Нельзя перевести заказ из статуса «{$from->label()}» в «{$to->label()}»",
            ]);
        }

        DB::transaction(function () use ($order, $from, $to, $by, $comment) {
            $order->update(['status' => $to]);
            $order->statusHistory()->create([
                'user_id' => $by?->id,
                'from_status' => $from,
                'to_status' => $to,
                'comment' => $comment,
                'created_at' => now(),
            ]);
        });

        OrderStatusChanged::dispatch($order, $from, $by, $comment);

        return $order;
    }
}
