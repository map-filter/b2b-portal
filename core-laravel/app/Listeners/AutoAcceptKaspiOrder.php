<?php

namespace App\Listeners;

use App\Events\Kaspi\KaspiOrderReceived;
use App\Models\Kaspi\KaspiSettings;
use App\Services\Kaspi\KaspiOrderActions;
use App\Services\Kaspi\KaspiStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Validation\ValidationException;

/**
 * Автоприём: заказ принимается сам, если все товары сопоставлены и есть на складе.
 */
class AutoAcceptKaspiOrder implements ShouldQueue
{
    public function __construct(private KaspiOrderActions $actions, private KaspiStock $stock) {}

    public function handle(KaspiOrderReceived $event): void
    {
        $order = $event->order->fresh('entries');

        if (! KaspiSettings::current()->auto_accept || ! in_array('accept', $order->availableActions(), true)) {
            return;
        }

        $missing = $order->entries->first(fn ($entry) => ! $entry->product_id
            || $this->stock->available($entry->product_id, $entry->store_id ?? $order->store_id) < $entry->quantity);

        if ($missing) {
            $order->log('auto', "Автоприём пропущен: «{$missing->name}» не хватает на складе или товар не сопоставлен");

            return;
        }

        try {
            $this->actions->accept($order);
            $order->log('auto', 'Принят автоматически: всё есть на складе');
        } catch (ValidationException $exception) {
            $order->log('auto', 'Автоприём не удался: '.collect($exception->errors())->flatten()->first());
        }
    }
}
