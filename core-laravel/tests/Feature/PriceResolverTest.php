<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractPrice;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Pricing\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private PriceType $wholesale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wholesale = PriceType::create(['name' => 'Оптовая', 'code' => 'wholesale']);
    }

    public function test_contract_price_wins_over_everything(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);
        $contract = $this->contract(discount: 10);
        ProductPrice::create(['product_id' => $product->id, 'price_type_id' => $this->wholesale->id, 'price' => 900]);
        ContractPrice::create(['contract_id' => $contract->id, 'product_id' => $product->id, 'price' => 555.55]);

        $this->assertSame('555.55', app(PriceResolver::class)->forProduct($contract, $product));
    }

    public function test_price_type_price_gets_contract_discount(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);
        ProductPrice::create(['product_id' => $product->id, 'price_type_id' => $this->wholesale->id, 'price' => 880]);

        $this->assertSame('836.00', app(PriceResolver::class)->forProduct($this->contract(discount: 5), $product));
    }

    public function test_falls_back_to_base_price_and_rounds_half_up(): void
    {
        $product = Product::factory()->create(['base_price' => 333.35]);

        $this->assertSame('316.68', app(PriceResolver::class)->forProduct($this->contract(discount: 5), $product));
    }

    public function test_resolves_many_products_at_once(): void
    {
        $products = Product::factory()->count(3)->create(['base_price' => 200]);

        $prices = app(PriceResolver::class)->forContract($this->contract(discount: 0), $products);

        $this->assertCount(3, $prices);
        $this->assertSame(['200.00'], $prices->unique()->values()->all());
    }

    private function contract(float $discount): Contract
    {
        return Contract::create([
            'company_id' => Company::factory()->create()->id,
            'price_type_id' => $this->wholesale->id,
            'number' => 'T-1',
            'starts_at' => '2026-01-01',
            'discount_percent' => $discount,
            'is_default' => true,
        ]);
    }
}
