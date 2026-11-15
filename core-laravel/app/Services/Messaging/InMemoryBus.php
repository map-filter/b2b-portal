<?php

namespace App\Services\Messaging;

class InMemoryBus implements MessageBus
{
    /** @var list<array{exchange: string, key: string, payload: array}> */
    public array $published = [];

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $this->published[] = ['exchange' => $exchange, 'key' => $routingKey, 'payload' => $payload];
    }

    public function keys(): array
    {
        return array_column($this->published, 'key');
    }
}
