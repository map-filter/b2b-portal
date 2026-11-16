<?php

namespace App\Models\Kaspi;

use App\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kaspi_order_id', 'kaspi_entry_id', 'product_id', 'sku', 'name', 'category', 'quantity', 'base_price', 'total_price', 'store_id'])]
class KaspiOrderEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(KaspiOrder::class, 'kaspi_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
