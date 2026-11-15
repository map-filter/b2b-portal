<?php

namespace App\Http\Resources\Kaspi;

use App\Enums\Kaspi\KaspiCancelReason;
use App\Models\Kaspi\KaspiOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KaspiOrder */
class KaspiOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery' => $this->channel(),
            'delivery_label' => $this->deliveryLabel(),
            'express' => $this->express,
            'payment_mode' => $this->payment_mode,
            'credit_term' => $this->credit_term,
            'total' => $this->total_price,
            'delivery_cost_for_seller' => $this->delivery_cost_for_seller,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'delivery_address' => $this->delivery_address,
            'store_id' => $this->store_id,
            'created_at' => $this->kaspi_created_at->toIso8601String(),
            'planned_delivery_at' => $this->planned_delivery_at?->toIso8601String(),
            'courier_planned_at' => $this->courier_planned_at?->toIso8601String(),
            'courier_transmitted_at' => $this->courier_transmitted_at?->toIso8601String(),
            'waybill_number' => $this->waybill_number,
            'number_of_space' => $this->number_of_space,
            'has_waybill' => (bool) $this->waybill_url,
            'cancellation' => KaspiCancelReason::describe($this->cancellation_reason),
            'cancellation_comment' => $this->cancellation_comment,
            'code_sent_at' => $this->code_sent_at?->toIso8601String(),
            'problem' => $this->problem,
            'items_count' => $this->entries_count ?? $this->whenLoaded('entries', fn () => $this->entries->count()),
            'actions' => $this->availableActions(),
            'core_order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status->value,
                'status_label' => $this->order->status->label(),
            ] : null),
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($entry) => [
                'id' => $entry->id,
                'sku' => $entry->sku,
                'name' => $entry->name,
                'category' => $entry->category,
                'quantity' => $entry->quantity,
                'price' => $entry->base_price,
                'total' => $entry->total_price,
                'store_id' => $entry->store_id,
                'product' => $entry->product ? ['id' => $entry->product->id, 'sku' => $entry->product->sku, 'name' => $entry->product->name, 'unit' => $entry->product->unit] : null,
                'stock' => $entry->stock ?? null,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'type' => $event->type,
                'text' => $event->text,
                'by' => $event->user?->name,
                'at' => $event->created_at->toIso8601String(),
            ])),
        ];
    }
}
