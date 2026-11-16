<?php

namespace App\Services\Kaspi;

use App\Enums\Kaspi\KaspiCancelReason;
use App\Enums\Kaspi\KaspiState;
use App\Enums\Kaspi\KaspiStatus;
use App\Enums\OrderStatus;
use App\Events\Kaspi\KaspiOrderReceived;
use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiSettings;

/**
 * Что происходит у продавца, когда заказ в Kaspi меняет статус: 1С, остатки, уведомления.
 */
class KaspiOrderFlow
{
    private const ACCEPTED = [KaspiStatus::AcceptedByMerchant, KaspiStatus::Arrived, KaspiStatus::Completed, KaspiStatus::ReturnRequested, KaspiStatus::Returned];

    public function __construct(
        private KaspiCoreOrders $core,
        private KaspiStock $stock,
    ) {}

    public function apply(KaspiOrder $order, ?KaspiOrder $before, bool $backfill): void
    {
        $settings = KaspiSettings::current();
        $status = $order->status;

        if ($before && $before->status !== $status) {
            $order->log('kaspi', "Статус в Kaspi: {$before->status->label()} → {$status->label()}");
        }
        if ($before && $before->state !== $order->state && $order->state !== KaspiState::Archive) {
            $order->log('kaspi', "Раздел в Kaspi: {$order->state->label()}");
        }

        if (! $before && $status === KaspiStatus::ApprovedByBank && ! $backfill) {
            KaspiOrderReceived::dispatch($order);
        }

        $wasAccepted = $before && in_array($before->status, self::ACCEPTED, true);
        $isAccepted = in_array($status, self::ACCEPTED, true);

        if ($isAccepted && ! $wasAccepted) {
            if ($settings->standalone()) {
                if (! $backfill && ! $order->stock_reserved) {
                    $this->stock->reserve($order);
                }
            } elseif (! ($backfill && $status->isFinal())) {
                $this->core->mirror($order);
            }
        }

        if (! $order->order_id) {
            $this->releaseIfNeeded($order);

            return;
        }

        $order->refresh();
        if ($order->waybill_number && ! $before?->waybill_number) {
            $order->log('kaspi', "Накладная {$order->waybill_number}, мест: {$order->number_of_space}");
        }

        match (true) {
            in_array($status, [KaspiStatus::Cancelled, KaspiStatus::Cancelling, KaspiStatus::Returned], true) => $this->core->advance($order, OrderStatus::Cancelled, 'Kaspi: '.(KaspiCancelReason::describe($order->cancellation_reason) ?? $status->label())),
            $status === KaspiStatus::Completed => $this->core->advance($order, OrderStatus::Completed, 'Kaspi: заказ выдан покупателю'),
            $order->courier_transmitted_at !== null => $this->core->advance($order, OrderStatus::Shipped, 'Kaspi: передан курьеру'),
            $order->waybill_number !== null => $this->core->advance($order, OrderStatus::Assembling, "Kaspi: накладная {$order->waybill_number}"),
            default => null,
        };
    }

    private function releaseIfNeeded(KaspiOrder $order): void
    {
        if (! $order->stock_reserved) {
            return;
        }

        if (in_array($order->status, [KaspiStatus::Cancelled, KaspiStatus::Cancelling], true)) {
            $this->stock->release($order, 'заказ отменён');
        } elseif ($order->status === KaspiStatus::Returned && $order->returned_to_warehouse) {
            $this->stock->release($order, 'товар вернулся на склад');
        }
    }
}
