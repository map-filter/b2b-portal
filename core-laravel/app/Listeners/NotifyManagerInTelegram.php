<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyManagerInTelegram implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private TelegramClient $telegram) {}

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order->load(['company.manager', 'user', 'items']);
        $chatId = $order->company->manager?->telegram_chat_id;

        if (! $this->telegram->enabled() || ! $chatId) {
            return;
        }

        $lines = $order->items->take(10)->map(fn ($item) => '• '.e($item->name)." × {$item->quantity} {$item->unit}")->implode("\n");
        $more = $order->items->count() > 10 ? "\n… и ещё ".($order->items->count() - 10) : '';
        $total = number_format((float) $order->total, 2, ',', ' ');
        $url = rtrim(config('app.url'), '/')."/manager/orders/{$order->id}";

        $this->telegram->send($chatId, "🆕 <b>Заказ {$order->number}</b>\n".e($order->company->name).', '.e($order->user->name)
            ."\n\n{$lines}{$more}\n\n<b>{$total} ₸</b>\n<a href=\"{$url}\">Открыть в панели</a>");
    }
}
