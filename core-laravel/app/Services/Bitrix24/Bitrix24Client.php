<?php

namespace App\Services\Bitrix24;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Bitrix24Client
{
    public function enabled(): bool
    {
        return filled(config('services.bitrix24.webhook'));
    }

    /**
     * @throws RequestException
     */
    public function call(string $method, array $params = []): mixed
    {
        $response = Http::baseUrl(rtrim(config('services.bitrix24.webhook'), '/').'/')
            ->acceptJson()
            ->timeout(15)
            ->retry(3, 1000, throw: false)
            ->post("{$method}.json", $params);

        if ($response->failed() || $response->json('error')) {
            throw new RuntimeException("Битрикс24 {$method}: ".($response->json('error_description') ?? $response->body()));
        }

        return $response->json('result');
    }
}
