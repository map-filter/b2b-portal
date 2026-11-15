<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'price_type_id', 'number', 'starts_at', 'ends_at', 'discount_percent', 'is_default', 'external_id'])]
class Contract extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'discount_percent' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ContractPrice::class);
    }

    public function isActiveOn(DateTimeInterface $date): bool
    {
        return $this->starts_at <= $date && ($this->ends_at === null || $this->ends_at >= $date);
    }
}
