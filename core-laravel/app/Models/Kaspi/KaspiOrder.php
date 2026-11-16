<?php

namespace App\Models\Kaspi;

use App\Enums\Kaspi\KaspiDeliveryMode;
use App\Enums\Kaspi\KaspiState;
use App\Enums\Kaspi\KaspiStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kaspi_id', 'code', 'order_id', 'state', 'status', 'delivery_mode', 'is_kaspi_delivery', 'express', 'payment_mode', 'credit_term',
    'signature_required', 'pre_order', 'total_price', 'delivery_cost', 'delivery_cost_for_seller', 'customer_name', 'customer_phone',
    'delivery_address', 'store_id', 'kaspi_created_at', 'approved_at', 'planned_delivery_at', 'courier_planned_at', 'courier_transmitted_at',
    'waybill_url', 'waybill_number', 'number_of_space', 'cancellation_reason', 'cancellation_comment', 'returned_to_warehouse',
    'code_sent_at', 'stock_reserved', 'problem', 'raw',
])]
class KaspiOrder extends Model
{
    protected function casts(): array
    {
        return [
            'state' => KaspiState::class,
            'status' => KaspiStatus::class,
            'delivery_mode' => KaspiDeliveryMode::class,
            'is_kaspi_delivery' => 'boolean',
            'express' => 'boolean',
            'signature_required' => 'boolean',
            'pre_order' => 'boolean',
            'returned_to_warehouse' => 'boolean',
            'stock_reserved' => 'boolean',
            'total_price' => 'decimal:2',
            'delivery_cost' => 'decimal:2',
            'delivery_cost_for_seller' => 'decimal:2',
            'kaspi_created_at' => 'datetime',
            'approved_at' => 'datetime',
            'planned_delivery_at' => 'datetime',
            'courier_planned_at' => 'datetime',
            'courier_transmitted_at' => 'datetime',
            'code_sent_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(KaspiOrderEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(KaspiOrderEvent::class)->latest('created_at')->latest('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function channel(): string
    {
        return $this->delivery_mode->channel($this->is_kaspi_delivery);
    }

    public function deliveryLabel(): string
    {
        return $this->delivery_mode->label($this->is_kaspi_delivery);
    }

    /**
     * @return list<string>
     */
    public function availableActions(): array
    {
        $actions = [];

        if ($this->status === KaspiStatus::ApprovedByBank && $this->state === KaspiState::New) {
            $actions[] = 'accept';
        }
        if (in_array($this->status, [KaspiStatus::ApprovedByBank, KaspiStatus::AcceptedByMerchant], true) && ! $this->courier_transmitted_at) {
            $actions[] = 'cancel';
        }
        if ($this->status === KaspiStatus::AcceptedByMerchant && $this->is_kaspi_delivery && ! $this->waybill_number) {
            $actions[] = 'assemble';
        }
        if (in_array($this->status, [KaspiStatus::AcceptedByMerchant, KaspiStatus::Arrived], true) && ! $this->is_kaspi_delivery) {
            $actions[] = 'send_code';
            if ($this->code_sent_at) {
                $actions[] = 'complete';
            }
        }

        return $actions;
    }

    public function log(string $type, string $text, ?int $userId = null): void
    {
        $this->events()->create(['type' => $type, 'text' => mb_substr($text, 0, 500), 'user_id' => $userId, 'created_at' => now()]);
    }
}
