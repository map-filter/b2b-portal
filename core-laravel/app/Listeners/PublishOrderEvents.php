<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Messaging\MessageBus;
use App\Services\Messaging\RabbitMq;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

class PublishOrderEvents implements ShouldQueue
{
    public int $tries = 5;

    public int $backoff = 15;

    public function __construct(private MessageBus $rabbit) {}

    public function placed(OrderPlaced $event): void
    {
        $this->rabbit->publish(RabbitMq::EVENTS_EXCHANGE, 'order.created', $this->payload($event->order));
    }

    public function statusChanged(OrderStatusChanged $event): void
    {
        $this->rabbit->publish(RabbitMq::EVENTS_EXCHANGE, 'order.status_changed', $this->payload($event->order) + [
            'previous_status' => $event->from->value,
            'changed_by' => $event->by?->name,
            'status_comment' => $event->comment,
        ]);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'placed',
            OrderStatusChanged::class => 'statusChanged',
        ];
    }

    private function payload(Order $order): array
    {
        $order->loadMissing(['company', 'contract', 'user', 'items.product']);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'created_at' => $order->created_at->toIso8601String(),
            'total' => (string) $order->total,
            'comment' => (string) $order->comment,
            'contract' => $order->contract->number,
            'user' => ['id' => $order->user->id, 'name' => $order->user->name, 'email' => $order->user->email, 'telegram_chat_id' => $order->user->telegram_chat_id],
            'company' => [
                'id' => $order->company->id,
                'external_id' => (string) $order->company->external_id,
                'name' => $order->company->name,
                'legal_name' => $order->company->legal_name,
                'bin' => $order->company->bin,
                'address' => (string) $order->delivery_address,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_external_id' => (string) $item->product?->external_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'price' => (string) $item->price,
                'total' => (string) $item->total,
            ])->all(),
        ];
    }
}
