<?php

namespace App\Services\Bitrix24;

use App\Models\Company;
use App\Models\Order;

class DealSync
{
    public function __construct(private Bitrix24Client $client) {}

    public function createDeal(Order $order): ?string
    {
        if (! $this->client->enabled() || $order->crm_deal_id) {
            return $order->crm_deal_id;
        }

        $order->loadMissing(['company', 'items', 'user']);

        $dealId = (string) $this->client->call('crm.deal.add', ['fields' => [
            'TITLE' => "Заказ {$order->number} с B2B-портала",
            'COMPANY_ID' => $this->companyId($order->company),
            'STAGE_ID' => config('services.bitrix24.stages.new'),
            'OPPORTUNITY' => (float) $order->total,
            'CURRENCY_ID' => 'KZT',
            'SOURCE_ID' => 'WEB',
            'ORIGIN_ID' => $order->number,
            'COMMENTS' => trim("Оформил: {$order->user->name}\n{$order->comment}"),
        ]]);

        $this->client->call('crm.deal.productrows.set', [
            'id' => $dealId,
            'rows' => $order->items->map(fn ($item) => [
                'PRODUCT_NAME' => "{$item->name} ({$item->sku})",
                'PRICE' => (float) $item->price,
                'QUANTITY' => $item->quantity,
                'MEASURE_NAME' => $item->unit,
            ])->all(),
        ]);

        $order->forceFill(['crm_deal_id' => $dealId])->saveQuietly();

        return $dealId;
    }

    public function syncStage(Order $order): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        $dealId = $order->crm_deal_id ?? $this->createDeal($order);

        $this->client->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => ['STAGE_ID' => config("services.bitrix24.stages.{$order->status->value}")],
        ]);
    }

    private function companyId(Company $company): int
    {
        $found = $this->client->call('crm.company.list', [
            'filter' => ['ORIGIN_ID' => "b2b-{$company->id}"],
            'select' => ['ID'],
        ]);

        if (! empty($found)) {
            return (int) $found[0]['ID'];
        }

        return (int) $this->client->call('crm.company.add', ['fields' => [
            'TITLE' => $company->legal_name,
            'ORIGIN_ID' => "b2b-{$company->id}",
            'COMPANY_TYPE' => 'CUSTOMER',
            'PHONE' => $company->phone ? [['VALUE' => $company->phone, 'VALUE_TYPE' => 'WORK']] : [],
            'EMAIL' => $company->email ? [['VALUE' => $company->email, 'VALUE_TYPE' => 'WORK']] : [],
            'COMMENTS' => "БИН {$company->bin}",
        ]]);
    }
}
