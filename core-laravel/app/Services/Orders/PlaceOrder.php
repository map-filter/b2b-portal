<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\OrderRejected;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\DB;

class PlaceOrder
{
    public function __construct(
        private CartService $cart,
        private OrderNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{comment?: ?string, delivery_address?: ?string, desired_date?: ?string}  $details
     */
    public function handle(User $user, array $details): Order
    {
        $company = $user->company;
        $contract = $company->defaultContract;

        if (! $contract?->isActiveOn(now())) {
            throw new OrderRejected('У компании нет действующего договора, обратитесь к менеджеру');
        }

        $cart = $this->cart->summary($user);

        if ($cart['count'] === 0) {
            throw new OrderRejected('Корзина пуста');
        }

        $this->validateItems($cart['items']);
        $this->validateCreditLimit($user, $cart['total']);

        $order = DB::transaction(function () use ($user, $company, $contract, $cart, $details) {
            $order = Order::create([
                'number' => $this->numbers->next(),
                'company_id' => $company->id,
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'status' => OrderStatus::New,
                'total' => $cart['total'],
                'comment' => $details['comment'] ?? null,
                'delivery_address' => $details['delivery_address'] ?? $company->legal_address,
                'desired_date' => $details['desired_date'] ?? null,
            ]);

            $order->items()->createMany($cart['items']->map(fn (CartItem $item) => [
                'product_id' => $item->product_id,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
                'unit' => $item->product->unit,
                'quantity' => $item->quantity,
                'price' => $item->product->contract_price,
                'total' => $item->total,
            ])->all());

            $order->statusHistory()->create([
                'user_id' => $user->id,
                'to_status' => OrderStatus::New,
                'created_at' => now(),
            ]);

            $this->cart->clear($user);

            return $order;
        });

        OrderPlaced::dispatch($order);

        return $order;
    }

    private function validateItems($items): void
    {
        $errors = [];

        foreach ($items as $item) {
            $product = $item->product;

            if (! $product->is_active) {
                $errors["items.{$product->id}"][] = "«{$product->name}» снят с продажи";
            } elseif ($item->quantity < $product->min_quantity) {
                $errors["items.{$product->id}"][] = "«{$product->name}»: минимальный заказ {$product->min_quantity} {$product->unit}";
            }
        }

        if ($errors) {
            throw new OrderRejected('Исправьте позиции в корзине', $errors);
        }
    }

    private function validateCreditLimit(User $user, string $total): void
    {
        $company = $user->company;

        if (bccomp((string) $company->credit_limit, '0', 2) <= 0) {
            return;
        }

        $open = (string) $company->orders()
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->sum('total');

        if (bccomp(bcadd($open, $total, 2), (string) $company->credit_limit, 2) > 0) {
            throw new OrderRejected('Превышен кредитный лимит компании, обратитесь к менеджеру', [
                'credit_limit' => ["Лимит {$company->credit_limit} ₸, в работе заказов на {$open} ₸"],
            ]);
        }
    }
}
