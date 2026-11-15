<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Catalog\CatalogPricing;
use App\Services\Catalog\ProductMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BotController extends Controller
{
    /**
     * Привязать чат Telegram к пользователю по коду из кабинета.
     */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'chat_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:64'],
        ]);

        $userId = Cache::pull("telegram-link:{$data['code']}");
        abort_unless($userId, 404, 'Код устарел, получите новый в кабинете');

        User::where('telegram_chat_id', $data['chat_id'])->update(['telegram_chat_id' => null, 'telegram_username' => null]);
        $user = User::findOrFail($userId);
        $user->update(['telegram_chat_id' => $data['chat_id'], 'telegram_username' => $data['username'] ?? null]);

        return response()->json(['data' => $this->present($user)]);
    }

    public function user(int $chatId): JsonResponse
    {
        $user = User::where('telegram_chat_id', $chatId)->where('is_active', true)->firstOrFail();

        return response()->json(['data' => $this->present($user)]);
    }

    public function userByEmail(Request $request): JsonResponse
    {
        $user = User::where('email', $request->validate(['email' => ['required', 'email']])['email'])->firstOrFail();

        return response()->json(['data' => $this->present($user)]);
    }

    /**
     * Подобрать товары по строкам заявки с ценами по договору пользователя.
     */
    public function match(Request $request, User $user, ProductMatcher $matcher, CatalogPricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.query' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $results = collect($data['lines'])->map(function (array $line) use ($matcher) {
            return ['line' => $line, 'matches' => $matcher->match($line['query'])];
        });

        $pricing->apply($user, $results->flatMap(fn ($row) => $row['matches']->pluck('product'))->unique('id')->values());

        return response()->json(['data' => $results->map(fn ($row) => [
            'query' => $row['line']['query'],
            'quantity' => (int) ceil($row['line']['quantity'] ?? 1),
            'candidates' => $row['matches']->map(fn ($match) => [
                'product_id' => $match['product']->id,
                'sku' => $match['product']->sku,
                'name' => $match['product']->name,
                'unit' => $match['product']->unit,
                'min_quantity' => $match['product']->min_quantity,
                'price' => $match['product']->contract_price,
                'stock' => (float) $match['product']->stock_total,
                'score' => $match['score'],
            ]),
        ])]);
    }

    public function addToCart(Request $request, User $user, CartService $cart): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        abort_unless($user->role->isClient() && $user->is_active, 403, 'Корзина доступна только сотрудникам клиента');

        foreach ($data['items'] as $item) {
            $product = Product::find($item['product_id']);
            $cart->add($user, $product, max($item['quantity'], $product->min_quantity));
        }

        $summary = $cart->summary($user);

        return response()->json([
            'count' => $summary['count'],
            'total' => $summary['total'],
            'url' => rtrim(config('app.url'), '/').'/cart',
        ]);
    }

    public function orders(User $user): JsonResponse
    {
        $orders = Order::where('company_id', $user->company_id)
            ->when(! $user->isStaff() && $user->role->value === 'client_buyer', fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->limit(5)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'number' => $order->number,
            'status_label' => $order->status->label(),
            'total' => $order->total,
            'created_at' => $order->created_at->toIso8601String(),
        ])]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'is_client' => $user->role->isClient(),
            'company' => $user->company?->name,
        ];
    }
}
