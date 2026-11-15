<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusChanged;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class ManagerTest extends ApiTestCase
{
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->staff()->create();
    }

    public function test_client_cannot_open_manager_api(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/manager/orders')->assertForbidden();
    }

    public function test_status_follows_allowed_transitions_and_is_logged(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $orderId = $this->placeOrder();
        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/manager/orders/{$orderId}/status", ['status' => 'shipped'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/manager/orders/{$orderId}/status", ['status' => 'confirmed', 'comment' => 'Ок'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Confirmed->value)
            ->assertJsonPath('data.history.0.comment', 'Ок')
            ->assertJsonPath('data.next_statuses.0.value', OrderStatus::Assembling->value);

        Event::assertDispatched(OrderStatusChanged::class, fn ($event) => $event->from === OrderStatus::New);
    }

    public function test_mine_filter_limits_to_managed_companies(): void
    {
        $this->placeOrder($this->clientCompany(['manager_id' => $this->manager->id]));
        $this->placeOrder();
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/manager/orders')->assertJsonCount(2, 'data');
        $this->getJson('/api/manager/orders?mine=1')->assertJsonCount(1, 'data');
    }

    public function test_contract_price_override_changes_client_price(): void
    {
        $company = $this->clientCompany();
        $product = $this->product(['base_price' => 1000]);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/manager/companies/{$company->id}/prices/{$product->id}", ['price' => 777])
            ->assertJsonPath('data.0.standard_price', '900.00')
            ->assertJsonPath('data.0.price', '777.00');

        Sanctum::actingAs($this->buyer($company));
        $this->getJson('/api/catalog/products')->assertJsonPath('data.0.price', '777.00');
    }

    public function test_manager_creates_client_user_and_blocks_him(): void
    {
        $company = $this->clientCompany();
        Sanctum::actingAs($this->manager);

        $created = $this->postJson("/api/manager/companies/{$company->id}/users", [
            'name' => 'Новый закупщик',
            'email' => 'new@client.demo',
            'role' => UserRole::ClientBuyer->value,
        ])->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'new@client.demo', 'password' => $created->json('data.password')])->assertOk();

        $this->patchJson("/api/manager/companies/{$company->id}/users/{$created->json('data.id')}/toggle")
            ->assertJsonPath('data.is_active', false);
    }

    public function test_dashboard_returns_period_totals(): void
    {
        $this->placeOrder();
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/manager/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonCount(7, 'daily')
            ->assertJsonStructure(['top_products' => [['sku', 'revenue']], 'top_companies' => [['name', 'revenue']]]);
    }

    private function placeOrder($company = null): int
    {
        $product = $this->product();
        Sanctum::actingAs($this->buyer($company));
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 1]);

        return $this->postJson('/api/orders')->assertCreated()->json('data.id');
    }
}
