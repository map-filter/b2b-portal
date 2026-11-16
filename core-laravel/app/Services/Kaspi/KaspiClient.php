<?php

namespace App\Services\Kaspi;

use App\Models\Kaspi\KaspiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class KaspiClient
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    public const PAGE_SIZE = 100;

    private int $requests = 0;

    public function __construct(private KaspiSettings $settings) {}

    public static function make(): self
    {
        return new self(KaspiSettings::current());
    }

    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * @param  array<string, string|int>  $filters  ключи вида [state] или [creationDate][$ge], префикс filter[orders] добавляется сам
     * @return array{data: list<array>, included: list<array>, meta: array}
     */
    public function orders(array $filters, int $page = 0, int $size = self::PAGE_SIZE): array
    {
        $query = ['page[number]' => $page, 'page[size]' => $size, 'include[orders]' => 'user'];
        foreach ($filters as $key => $value) {
            $query["filter[orders]{$key}"] = $value;
        }

        $body = $this->get('v2/orders', $query);

        return ['data' => $body['data'] ?? [], 'included' => $body['included'] ?? [], 'meta' => $body['meta'] ?? []];
    }

    public function orderByCode(string $code): ?array
    {
        return $this->orders(['[code]' => $code], 0, 1)['data'][0] ?? null;
    }

    /**
     * @return list<array>
     */
    public function entries(string $orderId): array
    {
        return $this->get("v2/orders/{$orderId}/entries")['data'] ?? [];
    }

    public function entryProduct(string $entryId): ?array
    {
        return $this->get("v2/orderentries/{$entryId}/product")['data'] ?? null;
    }

    public function merchantProduct(string $masterId): ?array
    {
        return $this->get("v2/masterproducts/{$masterId}/merchantProduct")['data'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $headers
     */
    public function changeStatus(string $orderId, array $attributes, array $headers = []): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->withBody(json_encode([
            'data' => ['type' => 'orders', 'id' => $orderId, 'attributes' => $attributes],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), self::MEDIA_TYPE)->post('v2/orders'));

        return $response['data'] ?? [];
    }

    public function download(string $url): string
    {
        $this->requests++;

        try {
            $response = Http::withHeaders(['X-Auth-Token' => $this->settings->token()])->timeout(30)->get($url);
        } catch (ConnectionException $exception) {
            throw new KaspiApiException('Kaspi недоступен: '.$exception->getMessage());
        }

        if ($response->failed()) {
            throw new KaspiApiException('Не удалось скачать файл из Kaspi', $response->status());
        }

        return $response->body();
    }

    private function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($path, $query));
    }

    private function send(callable $call): array
    {
        $token = $this->settings->token();
        if ($token === '') {
            throw new KaspiApiException('Не указан токен Kaspi API');
        }

        $http = Http::baseUrl($this->settings->apiUrl().'/')
            ->withHeaders(['X-Auth-Token' => $token, 'Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE])
            ->timeout(20)
            ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false);

        $this->requests++;

        try {
            /** @var Response $response */
            $response = $call($http);
        } catch (ConnectionException $exception) {
            throw new KaspiApiException('Kaspi недоступен: '.$exception->getMessage());
        }

        if ($response->failed()) {
            $title = $response->json('errors.0.title') ?? $response->json('message') ?? "HTTP {$response->status()}";

            throw new KaspiApiException("Kaspi: {$title}", $response->status());
        }

        return $response->json() ?? [];
    }
}
