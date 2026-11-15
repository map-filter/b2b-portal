<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'price_type_id', 'price'])]
class ProductPrice extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }
}
