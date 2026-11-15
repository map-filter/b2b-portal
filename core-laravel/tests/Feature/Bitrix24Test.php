<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

class Bitrix24Test extends ApiTestCase
{
    public function test_order_creates_company_and_deal_then_moves_stage(): void
    {
        config(['services.bitrix24.webhook' => 'https://demo.bitrix24.kz/rest/1/secret']);
        Http::fake([
            '*/crm.company.list.json' => Http::response(['result' => []]),
            '*/crm.company.add.json' => Http::response(['result' => 77]),
            '*/crm.deal.add.json' => Http::response(['result' => 501]),
            '*/crm.deal.productrows.set.json' => Http::response(['result' => true]),
            '*/crm.deal.update.json' => Http::response(['result' => true]),
        ]);

        $product = $this->product(['base_price' => 1000]);
        Sanctum::actingAs($this->buyer());
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 2]);
        $orderId = $this->postJson('/api/orders')->json('data.id');

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->patchJson("/api/manager/orders/{$orderId}/status", ['status' => 'confirmed'])
            ->assertJsonPath('data.crm_deal_id', '501');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'crm.deal.add.json')
            && $r['fields']['COMPANY_ID'] === 77
            && $r['fields']['OPPORTUNITY'] === 1800.0
            && $r['fields']['CURRENCY_ID'] === 'KZT');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'crm.deal.productrows.set.json') && $r['rows'][0]['QUANTITY'] === 2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'crm.deal.update.json') && $r['fields']['STAGE_ID'] === 'PREPARATION');
    }

    public function test_disabled_without_webhook(): void
    {
        config(['services.bitrix24.webhook' => null]);
        Http::fake();

        $product = $this->product();
        Sanctum::actingAs($this->buyer());
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 1]);
        $this->postJson('/api/orders')->assertCreated();

        Http::assertNothingSent();
    }
}
