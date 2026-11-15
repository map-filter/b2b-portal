<?php

namespace App\Services\Kaspi;

use App\Enums\Kaspi\KaspiDeliveryMode;
use App\Enums\Kaspi\KaspiState;
use App\Enums\Kaspi\KaspiStatus;
use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сохраняет заказ из ответа Kaspi API и запускает последствия смены статуса.
 */
class KaspiOrderRecorder
{
    public function __construct(private KaspiOrderFlow $flow) {}

    /**
     * @param  array{id: string, attributes: array}  $resource
     * @return 'created'|'updated'|'same'
     */
    public function record(KaspiClient $client, array $resource, bool $backfill): string
    {
        $attributes = $resource['attributes'] ?? [];
        $order = KaspiOrder::firstWhere('kaspi_id', $resource['id']);

        if ($order && $order->raw == $attributes) {
            return 'same';
        }

        $before = $order ? clone $order : null;
        $data = $this->columns($attributes, $order);

        $order = DB::transaction(function () use ($order, $resource, $data, $client) {
            if ($order) {
                $order->update($data);

                return $order;
            }

            $order = KaspiOrder::create($data + ['kaspi_id' => $resource['id']]);
            $this->entries($client, $order);
            $order->log('kaspi', 'Заказ получен из Kaspi: '.$order->status->label());

            return $order;
        });

        $this->flow->apply($order, $before, $backfill);

        return $before ? 'updated' : 'created';
    }

    private function columns(array $a, ?KaspiOrder $existing): array
    {
        $customer = $a['customer'] ?? [];
        $name = $customer['name'] ?? trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? ''));

        return [
            'code' => (string) ($a['code'] ?? $existing?->code),
            'state' => KaspiState::tryFrom($a['state'] ?? '') ?? $existing?->state ?? KaspiState::New,
            'status' => KaspiStatus::tryFrom($a['status'] ?? '') ?? $existing?->status ?? KaspiStatus::ApprovedByBank,
            'delivery_mode' => KaspiDeliveryMode::tryFrom($a['deliveryMode'] ?? '') ?? $existing?->delivery_mode ?? KaspiDeliveryMode::Local,
            'is_kaspi_delivery' => (bool) ($a['isKaspiDelivery'] ?? false),
            'express' => (bool) ($a['express'] ?? false),
            'payment_mode' => $a['paymentMode'] ?? null,
            'credit_term' => $a['creditTerm'] ?? null,
            'signature_required' => (bool) ($a['signatureRequired'] ?? false),
            'pre_order' => (bool) ($a['preOrder'] ?? false),
            'total_price' => $a['totalPrice'] ?? 0,
            'delivery_cost' => $a['deliveryCost'] ?? 0,
            'delivery_cost_for_seller' => $a['deliveryCostForSeller'] ?? 0,
            'customer_name' => $name ?: null,
            'customer_phone' => $customer['cellPhone'] ?? null,
            'delivery_address' => $a['deliveryAddress']['formattedAddress'] ?? null,
            'store_id' => $a['pickupPointId'] ?? $existing?->store_id,
            'kaspi_created_at' => $this->time($a['creationDate'] ?? null) ?? $existing?->kaspi_created_at ?? now(),
            'approved_at' => $this->time($a['approvedByBankDate'] ?? null),
            'planned_delivery_at' => $this->time($a['plannedDeliveryDate'] ?? null),
            'courier_planned_at' => $this->time($a['courierTransmissionPlanningDate'] ?? null),
            'courier_transmitted_at' => $this->time($a['courierTransmissionDate'] ?? null),
            'waybill_url' => $a['waybill'] ?? null,
            'waybill_number' => $a['waybillNumber'] ?? null,
            'number_of_space' => $a['numberOfSpace'] ?? null,
            'cancellation_reason' => $a['cancellationReason'] ?? null,
            'cancellation_comment' => $a['cancellationComment'] ?? null,
            'returned_to_warehouse' => (bool) ($a['returnedToWarehouse'] ?? false),
            'raw' => $a,
        ];
    }

    private function entries(KaspiClient $client, KaspiOrder $order): void
    {
        foreach ($client->entries($order->kaspi_id) as $entry) {
            $a = $entry['attributes'] ?? [];
            $masterId = $entry['relationships']['product']['data']['id'] ?? null;
            [$sku, $name] = $this->identify($client, $entry, $masterId);
            $link = $sku ? KaspiProduct::firstWhere('sku', $sku) : null;

            if ($link && $masterId && $link->kaspi_master_id !== $masterId) {
                $link->update(['kaspi_master_id' => $masterId]);
            }

            $order->entries()->create([
                'kaspi_entry_id' => $entry['id'],
                'product_id' => $link?->product_id ?? ($sku ? Product::where('sku', $sku)->value('id') : null),
                'sku' => $sku,
                'name' => $name,
                'category' => $a['category']['title'] ?? null,
                'quantity' => (int) ($a['quantity'] ?? 1),
                'base_price' => $a['basePrice'] ?? 0,
                'total_price' => $a['totalPrice'] ?? 0,
                'store_id' => $entry['relationships']['deliveryPointOfService']['data']['id'] ?? null,
            ]);
        }

        if (! $order->store_id) {
            $order->update(['store_id' => $order->entries()->value('store_id')]);
        }
    }

    /**
     * Артикул продавца лежит в merchantProduct.code, до него три шага: позиция → masterproduct → merchantProduct.
     *
     * @return array{?string, string}
     */
    private function identify(KaspiClient $client, array $entry, ?string $masterId): array
    {
        $offer = $entry['attributes']['offer'] ?? null;
        if (! empty($offer['code'])) {
            return [(string) $offer['code'], (string) ($offer['name'] ?? $offer['code'])];
        }

        $known = $masterId ? KaspiProduct::with('product')->firstWhere('kaspi_master_id', $masterId) : null;
        if ($known) {
            return [$known->sku, $known->product->name];
        }

        $master = $client->entryProduct($entry['id']);
        $name = (string) ($master['attributes']['name'] ?? 'Товар Kaspi');
        $merchant = $masterId ? $client->merchantProduct($masterId) : null;

        return [$merchant['attributes']['code'] ?? null, $name];
    }

    private function time(mixed $ms): ?Carbon
    {
        return is_numeric($ms) && $ms > 0 ? Carbon::createFromTimestampMs((int) $ms) : null;
    }
}
