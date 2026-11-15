<?php

namespace Tests\Feature\Kaspi;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Kaspi API в памяти: те же пути и форма ответов, что у настоящего, для Http::fake.
 */
class FakeKaspi
{
    public array $orders = [];

    public array $calls = [];

    public string $securityCode = '4321';

    private int $seq = 100;

    public function install(): self
    {
        Http::fake([
            'kaspi.test/*' => fn (Request $request) => $this->handle($request),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        return $this;
    }

    /**
     * @param  list<array{sku: string, quantity?: int, price?: float}>  $items
     */
    public function add(array $items, array $attributes = []): string
    {
        $id = base64_encode((string) ++$this->seq);
        $entries = [];
        $total = 0;
        foreach ($items as $index => $item) {
            $price = $item['price'] ?? 1000;
            $quantity = $item['quantity'] ?? 1;
            $total += $price * $quantity;
            $entries[] = ['id' => "{$id}-e{$index}", 'master' => 'M-'.$item['sku'], 'sku' => $item['sku'], 'quantity' => $quantity, 'price' => $price];
        }

        $this->orders[$id] = [
            'attributes' => $attributes + [
                'code' => (string) (500000000 + $this->seq),
                'totalPrice' => $total,
                'paymentMode' => 'PREPAID',
                'creationDate' => now()->subHour()->getTimestampMs(),
                'approvedByBankDate' => now()->subHour()->getTimestampMs(),
                'plannedDeliveryDate' => now()->addDay()->getTimestampMs(),
                'deliveryMode' => 'DELIVERY_PICKUP',
                'isKaspiDelivery' => false,
                'signatureRequired' => false,
                'state' => 'NEW',
                'status' => 'APPROVED_BY_BANK',
                'customer' => ['firstName' => 'Айгерим', 'lastName' => 'Демо', 'cellPhone' => '7051234567'],
                'pickupPointId' => 'PP1',
            ],
            'entries' => $entries,
        ];

        return $id;
    }

    public function set(string $id, array $attributes): void
    {
        $this->orders[$id]['attributes'] = $attributes + $this->orders[$id]['attributes'];
    }

    public function handle(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'headers' => $request->headers(), 'body' => $request->body()];

        if (($request->header('X-Auth-Token')[0] ?? null) !== 'test-token') {
            return Http::response(['errors' => [['status' => '401', 'title' => 'Unauthorized']]], 401);
        }

        return match (true) {
            $request->method() === 'GET' && $path === '/shop/api/v2/orders' => $this->list($query),
            $request->method() === 'POST' && $path === '/shop/api/v2/orders' => $this->change($request),
            (bool) preg_match('#^/shop/api/v2/orders/([^/]+)/entries$#', $path, $m) => $this->entries($m[1]),
            (bool) preg_match('#^/shop/api/v2/orderentries/([^/]+)/product$#', $path, $m) => $this->master($m[1]),
            (bool) preg_match('#^/shop/api/v2/masterproducts/([^/]+)/merchantProduct$#', $path, $m) => $this->merchant($m[1]),
            str_starts_with($path, '/waybill/') => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
            default => Http::response(['errors' => [['status' => '404', 'title' => 'Not found']]], 404),
        };
    }

    public function posted(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call['method'] === 'POST'));
    }

    private function list(array $query)
    {
        $filter = $query['filter']['orders'] ?? [];
        $data = [];
        foreach ($this->orders as $id => $order) {
            $a = $order['attributes'];
            if (isset($filter['code']) && $a['code'] !== $filter['code']) {
                continue;
            }
            if (isset($filter['state']) && $a['state'] !== $filter['state']) {
                continue;
            }
            if (isset($filter['creationDate']['$ge']) && $a['creationDate'] < (int) $filter['creationDate']['$ge']) {
                continue;
            }
            if (isset($filter['creationDate']['$le']) && $a['creationDate'] > (int) $filter['creationDate']['$le']) {
                continue;
            }
            $data[] = ['type' => 'orders', 'id' => $id, 'attributes' => $a];
        }

        return Http::response(['data' => $data, 'included' => [], 'meta' => ['pageCount' => $data ? 1 : 0, 'totalCount' => count($data)]]);
    }

    private function entries(string $id)
    {
        return Http::response(['data' => array_map(fn ($e) => [
            'type' => 'orderentries',
            'id' => $e['id'],
            'attributes' => ['quantity' => $e['quantity'], 'basePrice' => $e['price'], 'totalPrice' => $e['price'] * $e['quantity'], 'category' => ['code' => 'Master - X', 'title' => 'Электротовары']],
            'relationships' => [
                'product' => ['data' => ['type' => 'masterproducts', 'id' => $e['master']]],
                'deliveryPointOfService' => ['data' => ['type' => 'pointofservices', 'id' => 'PP1']],
            ],
        ], $this->orders[$id]['entries'] ?? [])]);
    }

    private function master(string $entryId)
    {
        foreach ($this->orders as $order) {
            foreach ($order['entries'] as $e) {
                if ($e['id'] === $entryId) {
                    return Http::response(['data' => ['type' => 'masterproducts', 'id' => $e['master'], 'attributes' => ['code' => '1'.crc32($e['sku']), 'name' => "Товар {$e['sku']}"]]]);
                }
            }
        }

        return Http::response([], 404);
    }

    private function merchant(string $masterId)
    {
        return Http::response(['data' => ['type' => 'merchantproducts', 'id' => 'MP-'.$masterId, 'attributes' => ['code' => substr($masterId, 2), 'name' => 'x']]]);
    }

    private function change(Request $request)
    {
        $data = $request->data()['data'];
        $id = $data['id'];
        $attrs = $data['attributes'];
        $order = &$this->orders[$id];
        $status = $attrs['status'];

        if ($status === 'ACCEPTED_BY_MERCHANT') {
            if ($order['attributes']['status'] !== 'APPROVED_BY_BANK') {
                return Http::response(['errors' => [['status' => '400', 'title' => 'Заказ уже принят']]], 400);
            }
            $order['attributes']['status'] = 'ACCEPTED_BY_MERCHANT';
            $order['attributes']['state'] = $order['attributes']['isKaspiDelivery'] ? 'KASPI_DELIVERY' : ($order['attributes']['deliveryMode'] === 'DELIVERY_PICKUP' ? 'PICKUP' : 'DELIVERY');
        } elseif ($status === 'ASSEMBLE') {
            $order['attributes']['waybill'] = "https://kaspi.test/waybill/{$order['attributes']['code']}.pdf";
            $order['attributes']['waybillNumber'] = 'KD'.$order['attributes']['code'];
            $order['attributes']['numberOfSpace'] = (int) $attrs['numberOfSpace'];
        } elseif ($status === 'COMPLETED') {
            $code = $request->header('X-Security-Code')[0] ?? '';
            if ($code !== '' && $code !== $this->securityCode) {
                return Http::response(['errors' => [['status' => '400', 'title' => 'Неверный код']]], 400);
            }
            if ($code !== '') {
                $order['attributes']['status'] = 'COMPLETED';
                $order['attributes']['state'] = 'ARCHIVE';
            }
        } elseif ($status === 'CANCELLED') {
            $order['attributes']['status'] = 'CANCELLED';
            $order['attributes']['state'] = 'ARCHIVE';
            $order['attributes']['cancellationReason'] = $attrs['cancellationReason'];
        }

        return Http::response(['data' => ['type' => 'orders', 'id' => $id, 'attributes' => ['status' => $status]]]);
    }
}
