<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItemRequest;
use App\Http\Resources\CartItemResource;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    /**
     * Установить количество товара в корзине.
     */
    public function put(CartItemRequest $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $this->cart->put($request->user(), $product, $request->integer('quantity'));

        return $this->respond($request);
    }

    public function remove(Request $request, Product $product): JsonResponse
    {
        $this->cart->remove($request->user(), $product);

        return $this->respond($request);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cart->clear($request->user());

        return $this->respond($request);
    }

    private function respond(Request $request): JsonResponse
    {
        $summary = $this->cart->summary($request->user());

        return response()->json([
            'items' => CartItemResource::collection($summary['items']),
            'total' => $summary['total'],
            'count' => $summary['count'],
        ]);
    }
}
