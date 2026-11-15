<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'next_statuses' => $this->when($request->user()?->isStaff(), fn () => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                $this->status->next(),
            )),
            'total' => $this->total,
            'items_count' => $this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count()),
            'comment' => $this->comment,
            'delivery_address' => $this->delivery_address,
            'desired_date' => $this->desired_date?->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
            'author' => $this->whenLoaded('user', fn () => $this->user->name),
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'crm_deal_id' => $this->when($request->user()?->isStaff(), $this->crm_deal_id),
            'contract' => $this->whenLoaded('contract', fn () => $this->contract->number),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'total' => $item->total,
            ])),
            'history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($change) => [
                'status' => $change->to_status->value,
                'status_label' => $change->to_status->label(),
                'by' => $change->user?->name,
                'comment' => $change->comment,
                'at' => $change->created_at->toIso8601String(),
            ])),
        ];
    }
}
