<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['import_id', 'file', 'size_bytes', 'duration_ms', 'stats', 'applied', 'finished_at'])]
class ExchangeImport extends Model
{
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'applied' => 'array',
            'finished_at' => 'datetime',
        ];
    }
}
