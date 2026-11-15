<?php

namespace App\Services\Messaging;

interface MessageBus
{
    public function publish(string $exchange, string $routingKey, array $payload): void;
}
