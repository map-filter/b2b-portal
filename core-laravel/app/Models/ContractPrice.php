<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['contract_id', 'product_id', 'price'])]
class ContractPrice extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
