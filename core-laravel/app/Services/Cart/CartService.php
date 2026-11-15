<?php

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogPricing;
use Illuminate\Support\Collection;

class CartService
{
    public function __construct(private CatalogPricing $pricing) {}

    /**
     * @return array{items: Collection<int, CartItem>, total: string, count: int}
     */
    public function summary(User $user): array
    {
        $items = $user->cartItems()
            ->with(['product' => fn ($query) => $query->withSum('stocks as stock_total', 'quantity')])
            ->oldest()
            ->get();

        $this->pricing->apply($user, $items->pluck('product'));

        $items->each(fn (CartItem $item) => $item->setAttribute(
            'total',
            bcmul($item->product->contract_price, (string) $item->quantity, 2),
        ));

        return [
            'items' => $items,
            'total' => $items->reduce(fn (string $sum, CartItem $item) => bcadd($sum, $item->total, 2), '0.00'),
            'count' => $items->count(),
        ];
    }

    public function put(User $user, Product $product, int $quantity): void
    {
        CartItem::updateOrCreate(
            ['user_id' => $user->id, 'product_id' => $product->id],
            ['quantity' => $quantity],
        );
    }

    public function add(User $user, Product $product, int $quantity): void
    {
        $item = CartItem::firstOrNew(['user_id' => $user->id, 'product_id' => $product->id]);
        $item->quantity = ($item->quantity ?? 0) + $quantity;
        $item->save();
    }

    public function remove(User $user, Product $product): void
    {
        $user->cartItems()->where('product_id', $product->id)->delete();
    }

    public function clear(User $user): void
    {
        $user->cartItems()->delete();
    }
}
