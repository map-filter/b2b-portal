<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Invoices\InvoiceRenderer;
use App\Services\Orders\PlaceOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    /**
     * Заказы компании. Закупщик видит свои, руководитель клиента все.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();

        $orders = Order::query()
            ->where('company_id', $user->company_id)
            ->when($user->role === UserRole::ClientBuyer, fn ($query) => $query->where('user_id', $user->id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where('number', 'ilike', '%'.$request->string('q').'%'))
            ->with('user')
            ->withCount('items')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(PlaceOrderRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $order = $placeOrder->handle($request->user(), $request->validated());

        return (new OrderResource($order->load(['items', 'user', 'contract', 'statusHistory.user'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(['items', 'user', 'contract', 'statusHistory.user']));
    }

    public function invoice(Order $order, InvoiceRenderer $renderer): StreamedResponse
    {
        Gate::authorize('view', $order);

        return Storage::download($renderer->ensure($order), "Счёт {$order->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /**
     * Повторить заказ: позиции добавляются в корзину по текущим ценам.
     */
    public function repeat(Request $request, Order $order, CartService $cart): JsonResponse
    {
        Gate::authorize('view', $order);

        $skipped = [];

        foreach ($order->items()->with('product')->get() as $item) {
            if (! $item->product?->is_active) {
                $skipped[] = $item->name;

                continue;
            }

            $cart->add($request->user(), $item->product, $item->quantity);
        }

        return response()->json([
            'added' => $order->items()->count() - count($skipped),
            'skipped' => $skipped,
        ]);
    }
}
