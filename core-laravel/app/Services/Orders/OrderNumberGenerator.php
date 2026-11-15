<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

class OrderNumberGenerator
{
    public function next(): string
    {
        $value = DB::selectOne("select nextval('order_number_seq') as value")->value;

        return 'B2B-'.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
