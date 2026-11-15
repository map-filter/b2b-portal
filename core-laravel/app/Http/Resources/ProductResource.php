<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => $this->brand,
            'unit' => $this->unit,
            'min_quantity' => $this->min_quantity,
            'pack_quantity' => $this->pack_quantity,
            'base_price' => $this->base_price,
            'price' => $this->contract_price ?? $this->base_price,
            'stock' => $this->when(isset($this->stock_total), fn () => (float) $this->stock_total),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'specs' => $this->specs,
            'description' => $this->when($request->routeIs('catalog.products.show'), $this->description),
            'warehouses' => $this->whenLoaded('stocks', fn () => $this->stocks->map(fn ($stock) => [
                'name' => $stock->warehouse->name,
                'quantity' => (float) $stock->quantity,
            ])),
        ];
    }
}
