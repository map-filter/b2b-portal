<?php

namespace App\Models\Kaspi;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['trigger', 'started_at', 'finished_at', 'requests', 'fetched', 'created', 'updated', 'error'])]
class KaspiSyncRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
