<?php

namespace App\Listeners;

use App\Events\Kaspi\KaspiOrderReceived;
use App\Models\Kaspi\KaspiSettings;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyAboutKaspiOrder implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private TelegramClient $telegram) {}

    public function handle(KaspiOrderReceived $event): void
    {
        $chatId = KaspiSettings::current()->notifyUser?->telegram_chat_id;

        if (! $this->telegram->enabled() || ! $chatId) {
            return;
        }

        $order = $event->order->load('entries');
        $lines = $order->entries->map(fn ($entry) => '• '.e($entry->name)." × {$entry->quantity}")->implode("\n");
        $total = number_format((float) $order->total_price, 0, ',', ' ');
        $url = rtrim(config('app.url'), '/')."/manager/kaspi/orders/{$order->id}";

        $this->telegram->send($chatId, "🛒 <b>Kaspi: новый заказ {$order->code}</b>\n".e($order->deliveryLabel())
            .($order->express ? ', экспресс' : '')."\n\n{$lines}\n\n<b>{$total} ₸</b>\n<a href=\"{$url}\">Принять в панели</a>");
    }
}
