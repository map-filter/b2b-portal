<?php

namespace App\Events\Kaspi;

use App\Models\Kaspi\KaspiOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KaspiOrderReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public KaspiOrder $order) {}
}
