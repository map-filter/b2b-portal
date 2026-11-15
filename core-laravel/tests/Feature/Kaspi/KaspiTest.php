<?php

namespace Tests\Feature\Kaspi;

use App\Enums\Kaspi\KaspiMode;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiProduct;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Kaspi\KaspiStore;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Kaspi\KaspiSync;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

class KaspiTest extends ApiTestCase
{
    private FakeKaspi $kaspi;

    private Warehouse $warehouse;

    private Product $cable;

    private Product $switch;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.kaspi.url' => 'https://kaspi.test/shop/api', 'services.kaspi.token' => 'test-token', 'services.telegram.token' => null]);
        $this->kaspi = (new FakeKaspi)->install();

        $this->warehouse = Warehouse::create(['name' => 'Павлодар']);
        KaspiStore::create(['store_id' => 'PP1', 'name' => 'Склад', 'warehouse_id' => $this->warehouse->id]);
        KaspiSettings::create(['pricelist_token' => 'price-token', 'merchant_id' => 'M1', 'company_name' => 'Демо']);

        $this->cable = $this->product(['sku' => 'CAB-1', 'name' => 'Кабель']);
        $this->switch = $this->product(['sku' => 'SW-1', 'name' => 'Выключатель']);
        foreach ([$this->cable, $this->switch] as $product) {
            KaspiProduct::create(['product_id' => $product->id, 'sku' => $product->sku, 'price' => 1500]);
            Stock::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10]);
        }

        Sanctum::actingAs(User::factory()->staff()->create());
    }

    public function test_first_sync_imports_history_without_side_effects_and_maps_sku_through_merchant_product(): void
    {
        $archived = $this->kaspi->add([['sku' => 'CAB-1', 'quantity' => 2]], ['state' => 'ARCHIVE', 'status' => 'COMPLETED']);
        $this->kaspi->add([['sku' => 'SW-1']]);

        $run = app(KaspiSync::class)->run();

        $this->assertNull($run->error);
        $this->assertSame(2, $run->created);
        $this->assertSame(0, Order::count(), 'история не должна уходить в 1С');
        $entry = KaspiOrder::firstWhere('kaspi_id', $archived)->entries()->first();
        $this->assertSame($this->cable->id, $entry->product_id);
        $this->assertSame(2, $entry->quantity);
        $this->assertNotNull(KaspiProduct::firstWhere('sku', 'CAB-1')->kaspi_master_id);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'telegram'));

        $again = app(KaspiSync::class)->run();
        $this->assertSame(0, $again->created + $again->updated, 'повторный опрос без изменений ничего не трогает');
    }

    public function test_new_order_after_first_sync_notifies_manager_in_telegram(): void
    {
        config(['services.telegram.token' => 'bot-token']);
        $manager = User::factory()->staff()->create(['telegram_chat_id' => 555]);
        KaspiSettings::current()->update(['notify_user_id' => $manager->id, 'last_synced_at' => now()->subMinutes(5)]);
        $this->kaspi->add([['sku' => 'CAB-1']]);

        app(KaspiSync::class)->run();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && $r['chat_id'] === 555 && str_contains($r['text'], 'Kaspi: новый заказ'));
    }

    public function test_accepted_order_goes_to_1c_and_follows_kaspi_delivery_statuses(): void
    {
        KaspiSettings::current()->update(['last_synced_at' => now()->subMinutes(5)]);
        $id = $this->kaspi->add([['sku' => 'CAB-1', 'quantity' => 3, 'price' => 900]], ['deliveryMode' => 'DELIVERY_LOCAL', 'isKaspiDelivery' => true]);
        app(KaspiSync::class)->run();
        $order = KaspiOrder::firstWhere('kaspi_id', $id);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.state', 'KASPI_DELIVERY')
            ->assertJsonPath('data.core_order.status', 'confirmed')
            ->assertJsonPath('data.actions', ['cancel', 'assemble']);

        $core = Order::firstWhere('source', 'kaspi');
        $this->assertSame('KSP-'.$order->code, $core->number);
        $this->assertSame('2700.00', (string) $core->total);
        $this->assertSame('kaspi', $core->company->external_id);
        $this->assertContains('order.created', $this->bus->keys());
        $created = collect($this->bus->published)->firstWhere('key', 'order.created')['payload'];
        $this->assertSame('CAB-1', $created['items'][0]['sku']);

        $accept = $this->kaspi->posted()[0];
        $this->assertSame('application/vnd.api+json', $accept['headers']['Content-Type'][0]);
        $this->assertStringContainsString('"status":"ACCEPTED_BY_MERCHANT"', $accept['body']);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/assemble", ['places' => 2])
            ->assertOk()
            ->assertJsonPath('data.waybill_number', 'KD'.$order->code)
            ->assertJsonPath('data.core_order.status', 'assembling');
        $this->get("/api/manager/kaspi/orders/{$order->id}/waybill")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->kaspi->set($id, ['courierTransmissionDate' => now()->getTimestampMs()]);
        app(KaspiSync::class)->run();
        $this->assertSame(OrderStatus::Shipped, $core->refresh()->status);

        $this->kaspi->set($id, ['status' => 'COMPLETED', 'state' => 'ARCHIVE']);
        app(KaspiSync::class)->run();
        $this->assertSame(OrderStatus::Completed, $core->refresh()->status);

        $this->patchJson("/api/manager/orders/{$core->id}/status", ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_pickup_is_completed_with_buyer_code(): void
    {
        KaspiSettings::current()->update(['last_synced_at' => now()]);
        $id = $this->kaspi->add([['sku' => 'SW-1']]);
        app(KaspiSync::class)->run();
        $order = KaspiOrder::firstWhere('kaspi_id', $id);
        $this->postJson("/api/manager/kaspi/orders/{$order->id}/accept")->assertJsonPath('data.actions', ['cancel', 'send_code']);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/send-code")
            ->assertOk()
            ->assertJsonPath('data.actions', ['cancel', 'send_code', 'complete']);
        $sent = collect($this->kaspi->posted())->last();
        $this->assertSame('true', $sent['headers']['X-Send-Code'][0]);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/complete", ['code' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.kaspi.0', 'Kaspi: Неверный код');
        $this->postJson("/api/manager/kaspi/orders/{$order->id}/complete", ['code' => '4321'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.core_order.status', 'completed');
    }

    public function test_standalone_mode_reserves_stock_on_accept_and_returns_it_on_cancel(): void
    {
        KaspiSettings::current()->update(['mode' => KaspiMode::Standalone, 'last_synced_at' => now()]);
        $id = $this->kaspi->add([['sku' => 'CAB-1', 'quantity' => 4]]);
        app(KaspiSync::class)->run();
        $order = KaspiOrder::firstWhere('kaspi_id', $id);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/accept")->assertOk()->assertJsonPath('data.core_order', null);
        $this->assertSame(6.0, $this->stockOf($this->cable));
        $this->assertSame(10.0, $this->stockOf($this->switch));
        $this->assertSame(0, Order::count(), 'без 1С заказ в обмен не уходит');

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/cancel", ['reason' => 'MERCHANT_OUT_OF_STOCK'])
            ->assertOk()
            ->assertJsonPath('data.cancellation', 'Товара нет в наличии');
        $this->assertSame(10.0, $this->stockOf($this->cable));
    }

    public function test_auto_accept_takes_order_only_when_everything_is_in_stock(): void
    {
        KaspiSettings::current()->update(['auto_accept' => true, 'last_synced_at' => now()]);
        $ok = $this->kaspi->add([['sku' => 'CAB-1', 'quantity' => 2]]);
        $tooMany = $this->kaspi->add([['sku' => 'SW-1', 'quantity' => 50]]);

        app(KaspiSync::class)->run();

        $this->assertSame('ACCEPTED_BY_MERCHANT', KaspiOrder::firstWhere('kaspi_id', $ok)->status->value);
        $skipped = KaspiOrder::firstWhere('kaspi_id', $tooMany);
        $this->assertSame('APPROVED_BY_BANK', $skipped->status->value);
        $this->assertTrue($skipped->events()->where('type', 'auto')->exists());
    }

    public function test_order_with_unknown_product_is_flagged_and_not_sent_to_1c(): void
    {
        KaspiSettings::current()->update(['last_synced_at' => now()]);
        $id = $this->kaspi->add([['sku' => 'UNKNOWN-9']]);
        app(KaspiSync::class)->run();
        $order = KaspiOrder::firstWhere('kaspi_id', $id);

        $this->postJson("/api/manager/kaspi/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.problem', 'Не сопоставлены товары: UNKNOWN-9');
        $this->assertSame(0, Order::count());
        $this->getJson('/api/manager/kaspi/orders?problem=1')->assertJsonPath('meta.problems', 1)->assertJsonCount(1, 'data');
    }

    public function test_price_list_xml_follows_kaspi_format(): void
    {
        $type = PriceType::create(['name' => 'Kaspi', 'code' => 'kaspi']);
        ProductPrice::create(['product_id' => $this->switch->id, 'price_type_id' => $type->id, 'price' => 2222]);
        KaspiSettings::current()->update(['price_type_id' => $type->id]);
        Stock::where('product_id', $this->cable->id)->update(['quantity' => 0]);
        $hidden = $this->product(['sku' => 'HID-1']);
        KaspiProduct::create(['product_id' => $hidden->id, 'sku' => 'HID-1', 'price' => 10, 'is_published' => false]);

        $this->get('/api/kaspi/pricelist/wrong.xml')->assertNotFound();
        $xml = simplexml_load_string($this->get('/api/kaspi/pricelist/price-token.xml')->assertOk()->getContent(), options: LIBXML_NOWARNING);

        $this->assertSame('kaspi_catalog', $xml->getName());
        $this->assertSame('M1', (string) $xml->merchantid);
        $offers = collect();
        foreach ($xml->offers->offer as $offer) {
            $offers[(string) $offer['sku']] = $offer;
        }
        $this->assertEqualsCanonicalizing(['CAB-1', 'SW-1'], $offers->keys()->all());
        $this->assertSame('no', (string) $offers['CAB-1']->availabilities->availability['available']);
        $this->assertSame('PP1', (string) $offers['SW-1']->availabilities->availability['storeId']);
        $this->assertSame('10', (string) $offers['SW-1']->availabilities->availability['stockCount']);
        $this->assertSame('2222', (string) $offers['SW-1']->price);
        $this->assertSame('1500', (string) $offers['CAB-1']->price);
    }

    public function test_standalone_seller_updates_stock_from_panel_and_excel(): void
    {
        KaspiSettings::current()->update(['mode' => KaspiMode::Standalone]);
        $link = KaspiProduct::firstWhere('sku', 'CAB-1');
        $newOne = $this->product(['sku' => 'NEW-7']);

        $this->patchJson("/api/manager/kaspi/products/{$link->id}", ['stocks' => ['PP1' => 3], 'price' => 1990])->assertOk();
        $this->assertSame(3.0, $this->stockOf($this->cable));
        $this->assertSame(10.0, $this->stockOf($this->switch), 'остаток другого товара не должен меняться');

        $csv = UploadedFile::fake()->createWithContent('kaspi.csv', "Артикул;Цена;Остаток\nCAB-1;2100;7\nNEW-7;500;2\nNOPE;1;1\nSW-1;-5;1\n");
        $this->post('/api/manager/kaspi/products/import', ['file' => $csv])
            ->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('linked', 1)
            ->assertJsonPath('not_found.0.sku', 'NOPE')
            ->assertJsonCount(1, 'invalid');

        $this->assertSame(7.0, $this->stockOf($this->cable));
        $this->assertSame('2100.00', (string) $link->refresh()->price);
        $this->assertSame(2.0, $this->stockOf($newOne));

        $this->getJson('/api/manager/kaspi/products?filter=all')->assertOk()->assertJsonPath('meta.counts.all', 3);
    }

    public function test_one_c_mode_does_not_let_panel_edit_stock(): void
    {
        $link = KaspiProduct::firstWhere('sku', 'CAB-1');

        $this->patchJson("/api/manager/kaspi/products/{$link->id}", ['stocks' => ['PP1' => 1]])->assertStatus(422);
    }

    public function test_dashboard_and_settings(): void
    {
        $this->kaspi->add([['sku' => 'CAB-1', 'quantity' => 2, 'price' => 1000]], ['state' => 'ARCHIVE', 'status' => 'COMPLETED']);
        $this->kaspi->add([['sku' => 'SW-1', 'price' => 500]], ['state' => 'ARCHIVE', 'status' => 'CANCELLED']);
        $this->postJson('/api/manager/kaspi/sync')->assertOk()->assertJsonPath('data.created', 2);

        $this->getJson('/api/manager/kaspi/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('summary.revenue', '2000.00')
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonPath('summary.cancel_rate', 50)
            ->assertJsonPath('top_products.0.sku', 'CAB-1');

        $this->putJson('/api/manager/kaspi/settings', ['api_token' => 'new-secret-token', 'poll_minutes' => 5, 'stores' => [
            ['store_id' => 'PP1', 'name' => 'Склад', 'warehouse_id' => $this->warehouse->id],
            ['store_id' => 'PP2', 'name' => 'Второй', 'warehouse_id' => $this->warehouse->id],
        ]])->assertOk()->assertJsonPath('data.poll_minutes', 5)->assertJsonCount(2, 'data.stores');

        $this->assertSame('new-secret-token', KaspiSettings::current()->token());
        $this->assertStringNotContainsString('new-secret-token', $this->getJson('/api/manager/kaspi/settings')->getContent());
    }

    public function test_kaspi_section_is_for_staff_only(): void
    {
        Sanctum::actingAs($this->buyer(role: UserRole::ClientAdmin));

        $this->getJson('/api/manager/kaspi/orders')->assertForbidden();
    }

    private function stockOf(Product $product): float
    {
        return (float) Stock::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity');
    }
}
