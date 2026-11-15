<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ExchangeImport;
use App\Models\PriceType;
use App\Models\Product;
use App\Services\Exchange\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeSyncTest extends TestCase
{
    use RefreshDatabase;

    private CatalogSync $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = app(CatalogSync::class);
    }

    public function test_full_import_creates_catalog_with_prices_and_stocks(): void
    {
        PriceType::create(['name' => 'Розничная', 'code' => 'retail']);

        $this->send('catalog.categories', [
            ['external_id' => 'g1', 'name' => 'Кабель'],
            ['external_id' => 'g2', 'parent_external_id' => 'g1', 'name' => 'Силовой'],
        ]);
        $this->send('catalog.products', [
            ['external_id' => 'p1', 'sku' => 'X-1', 'name' => 'Кабель ВВГ', 'category_external_id' => 'g2', 'unit' => 'м', 'specs' => ['Сечение' => '3x2.5'], 'deleted' => false],
            ['external_id' => 'p2', 'sku' => '', 'name' => 'Без артикула', 'category_external_id' => 'missing', 'unit' => '', 'specs' => [], 'deleted' => true],
        ]);
        $this->send('catalog.price_types', [['external_id' => 'pt1', 'name' => 'Розничная', 'currency' => 'KZT']]);
        $this->send('catalog.warehouses', [['external_id' => 'w1', 'name' => 'Основной']]);
        $this->send('catalog.prices', [['product_external_id' => 'p1', 'price_type_external_id' => 'pt1', 'price' => '640.50']]);
        $this->send('catalog.stocks', [['product_external_id' => 'p1', 'warehouse_external_id' => 'w1', 'quantity' => '1200']]);
        $this->sync->handle('catalog.import_finished', ['id' => 'imp-1', 'file' => 'import.xml', 'stats' => ['products' => 2]]);

        $cable = Product::where('external_id', 'p1')->first();
        $this->assertSame('640.50', $cable->base_price);
        $this->assertSame('Силовой', $cable->category->name);
        $this->assertSame('Кабель', $cable->category->parent->name);
        $this->assertSame(['Сечение' => '3x2.5'], $cable->specs);
        $this->assertEquals(1200, $cable->stocks()->sum('quantity'));
        $this->assertSame('pt1', PriceType::where('code', 'retail')->value('external_id'));

        $orphan = Product::where('external_id', 'p2')->first();
        $this->assertFalse($orphan->is_active);
        $this->assertSame('p2', $orphan->sku);
        $this->assertSame('Без категории (1С)', $orphan->category->name);

        $import = ExchangeImport::where('import_id', 'imp-1')->first();
        $this->assertEquals(['categories' => 2, 'products' => 2, 'price_types' => 1, 'warehouses' => 1, 'prices' => 1, 'stocks' => 1], $import->applied);
        $this->assertNotNull($import->finished_at);
    }

    public function test_repeated_import_is_idempotent_and_links_existing_sku(): void
    {
        $category = Category::factory()->create();
        $existing = Product::factory()->create(['sku' => 'OLD-1', 'category_id' => $category->id]);

        $payload = [['external_id' => 'p-old', 'sku' => 'OLD-1', 'name' => 'Обновлено из 1С', 'category_external_id' => '', 'unit' => 'шт', 'specs' => [], 'deleted' => false]];
        $this->send('catalog.products', $payload);
        $this->send('catalog.products', $payload);

        $this->assertSame(1, Product::where('sku', 'OLD-1')->count());
        $this->assertSame('p-old', $existing->fresh()->external_id);
        $this->assertSame('Обновлено из 1С', $existing->fresh()->name);
    }

    private function send(string $key, array $items): void
    {
        $this->sync->handle($key, ['import_id' => 'imp-1', 'items' => $items]);
    }
}
