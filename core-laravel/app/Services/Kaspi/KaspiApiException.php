<?php

namespace App\Services\Kaspi;

use RuntimeException;

class KaspiApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
