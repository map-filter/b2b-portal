<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Services\Bitrix24\DealSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

class SyncOrderToBitrix24 implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(private DealSync $deals) {}

    public function placed(OrderPlaced $event): void
    {
        $this->deals->createDeal($event->order);
    }

    public function statusChanged(OrderStatusChanged $event): void
    {
        $this->deals->syncStage($event->order->refresh());
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'placed',
            OrderStatusChanged::class => 'statusChanged',
        ];
    }
}
