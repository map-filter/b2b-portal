<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Laravel\Sanctum\Sanctum;

class CatalogTest extends ApiTestCase
{
    public function test_prices_are_calculated_by_client_contract(): void
    {
        $this->product(['base_price' => 1000]);
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.base_price', '1000.00')
            ->assertJsonPath('data.0.price', '900.00');
    }

    public function test_filters_by_parent_category_and_specs(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $this->product(['category_id' => $child->id, 'specs' => ['Ток, А' => 16]]);
        $this->product(['category_id' => $child->id, 'specs' => ['Ток, А' => 25]]);
        $this->product();
        Sanctum::actingAs($this->buyer());

        $this->getJson("/api/catalog/products?category={$root->id}")->assertJsonCount(2, 'data');
        $this->getJson("/api/catalog/products?category={$root->id}&".http_build_query(['specs' => ['Ток, А' => 25]]))
            ->assertJsonCount(1, 'data');
        $this->getJson("/api/catalog/facets?category={$root->id}")
            ->assertJsonPath('specs.Ток, А', ['16', '25']);
    }

    public function test_inactive_products_are_hidden(): void
    {
        $this->product(['is_active' => false]);
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/catalog/products')->assertJsonCount(0, 'data');
    }
}
