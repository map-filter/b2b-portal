<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'external_id'])]
class Warehouse extends Model
{
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
