<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class OrderTest extends ApiTestCase
{
    public function test_order_is_placed_from_cart_with_contract_prices(): void
    {
        Event::fake([OrderPlaced::class]);
        $product = $this->product(['base_price' => 500]);
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);

        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 3])
            ->assertJsonPath('total', '1350.00');

        $this->postJson('/api/orders', ['comment' => 'Срочно'])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::New->value)
            ->assertJsonPath('data.total', '1350.00')
            ->assertJsonPath('data.items.0.price', '450.00');

        $this->getJson('/api/cart')->assertJsonPath('count', 0);
        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_min_quantity_is_enforced(): void
    {
        $product = $this->product(['min_quantity' => 100, 'unit' => 'м']);
        Sanctum::actingAs($this->buyer());

        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 50]);

        $this->postJson('/api/orders')->assertUnprocessable()->assertJsonValidationErrors("items.{$product->id}");
    }

    public function test_credit_limit_blocks_order(): void
    {
        $product = $this->product(['base_price' => 1000]);
        Sanctum::actingAs($this->buyer($this->clientCompany(['credit_limit' => 1500], discount: 0)));

        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 2]);

        $this->postJson('/api/orders')->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    }

    public function test_buyer_sees_only_own_orders_and_admin_sees_company(): void
    {
        $company = $this->clientCompany();
        $product = $this->product();
        $first = $this->buyer($company);
        $second = $this->buyer($company);
        $admin = $this->buyer($company, UserRole::ClientAdmin);

        foreach ([$first, $second] as $user) {
            Sanctum::actingAs($user);
            $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 1]);
            $this->postJson('/api/orders')->assertCreated();
        }

        Sanctum::actingAs($first);
        $this->getJson('/api/orders')->assertJsonCount(1, 'data');
        $this->getJson('/api/orders/'.$second->company->orders()->where('user_id', $second->id)->value('id'))->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/orders')->assertJsonCount(2, 'data');
    }

    public function test_foreign_company_order_is_forbidden(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->buyer());
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 1]);
        $orderId = $this->postJson('/api/orders')->json('data.id');

        Sanctum::actingAs($this->buyer(null, UserRole::ClientAdmin));
        $this->getJson("/api/orders/{$orderId}")->assertForbidden();
    }

    public function test_repeat_order_fills_cart(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->buyer());
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 4]);
        $orderId = $this->postJson('/api/orders')->json('data.id');

        $this->postJson("/api/orders/{$orderId}/repeat")->assertJsonPath('added', 1);
        $this->getJson('/api/cart')->assertJsonPath('items.0.quantity', 4);
    }

    public function test_staff_cannot_use_client_cart(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/cart')->assertForbidden();
    }
}
