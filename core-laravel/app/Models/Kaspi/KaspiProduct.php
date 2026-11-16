<?php

namespace App\Models\Kaspi;

use App\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'sku', 'price', 'is_published', 'kaspi_master_id'])]
class KaspiProduct extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_published' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
