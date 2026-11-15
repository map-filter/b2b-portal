<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class BotIntegrationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.integration.token' => 'test-token']);
    }

    public function test_integration_api_requires_token(): void
    {
        $this->getJson('/api/integration/telegram/users/1')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/integration/telegram/users/1')->assertUnauthorized();
    }

    public function test_link_code_binds_chat_once(): void
    {
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);
        $code = $this->postJson('/api/telegram/link')->assertOk()->json('code');

        $this->withToken('test-token')->postJson('/api/integration/telegram/link', ['code' => $code, 'chat_id' => 555, 'username' => 'buyer'])
            ->assertOk()
            ->assertJsonPath('data.id', $buyer->id);
        $this->withToken('test-token')->postJson('/api/integration/telegram/link', ['code' => $code, 'chat_id' => 555])->assertNotFound();

        $this->withToken('test-token')->getJson('/api/integration/telegram/users/555')->assertJsonPath('data.is_client', true);
    }

    public function test_match_and_fill_cart_with_contract_prices(): void
    {
        $buyer = $this->buyer();
        $cable = $this->product(['name' => 'Кабель ВВГнг(А)-LS 3x2.5 мм²', 'base_price' => 500, 'unit' => 'м', 'min_quantity' => 100]);
        $this->product(['name' => 'Кабель ВВГнг(А)-LS 3x1.5 мм²', 'base_price' => 300]);

        $match = $this->withToken('test-token')->postJson("/api/integration/users/{$buyer->id}/match", [
            'lines' => [['query' => 'ввгнг 3х2,5', 'quantity' => 50], ['query' => 'что-то непонятное', 'quantity' => 1]],
        ])->assertOk();

        $match->assertJsonPath('data.0.candidates.0.product_id', $cable->id)
            ->assertJsonPath('data.0.candidates.0.price', '450.00')
            ->assertJsonCount(0, 'data.1.candidates');

        $this->withToken('test-token')->postJson("/api/integration/users/{$buyer->id}/cart", [
            'items' => [['product_id' => $cable->id, 'quantity' => 50]],
        ])->assertJsonPath('count', 1)->assertJsonPath('total', '45000.00');
    }

    public function test_manager_gets_telegram_message_about_new_order(): void
    {
        config(['services.telegram.token' => '123:abc']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $manager = User::factory()->staff()->create(['telegram_chat_id' => 999]);
        $product = $this->product(['name' => 'Лампа E27']);

        Sanctum::actingAs($this->buyer($this->clientCompany(['manager_id' => $manager->id])));
        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 3]);
        $number = $this->postJson('/api/orders')->json('data.number');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/bot123:abc/sendMessage')
            && $request['chat_id'] === 999
            && str_contains($request['text'], $number)
            && str_contains($request['text'], 'Лампа E27 × 3'));
    }
}
