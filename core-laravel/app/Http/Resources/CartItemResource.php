<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartItem */
class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product' => new ProductResource($this->product),
            'quantity' => $this->quantity,
            'total' => $this->total,
            'warnings' => array_values(array_filter([
                $this->quantity < $this->product->min_quantity ? "Минимальный заказ {$this->product->min_quantity} {$this->product->unit}" : null,
                isset($this->product->stock_total) && $this->quantity > $this->product->stock_total ? 'Больше, чем на складах: часть под заказ' : null,
            ])),
        ];
    }
}
