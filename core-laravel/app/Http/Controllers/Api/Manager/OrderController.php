<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\ChangeOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Заказы всех клиентов. mine=1 оставляет только клиентов текущего менеджера.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'company' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:50'],
            'mine' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $orders = Order::query()
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('company'), fn (Builder $query) => $query->where('company_id', $request->integer('company')))
            ->when($request->boolean('mine'), fn (Builder $query) => $query->whereHas('company', fn ($q) => $q->where('manager_id', $request->user()->id)))
            ->when($request->filled('q'), fn (Builder $query) => $query->where(fn ($q) => $q
                ->where('number', 'ilike', '%'.$request->string('q').'%')
                ->orWhereHas('company', fn ($c) => $c->where('name', 'ilike', '%'.$request->string('q').'%'))))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->with(['company', 'user'])
            ->withCount('items')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['company', 'user', 'contract', 'items', 'statusHistory.user']));
    }

    public function updateStatus(Request $request, Order $order, ChangeOrderStatus $changeStatus): OrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $changeStatus->handle($order, OrderStatus::from($data['status']), $request->user(), $data['comment'] ?? null);

        return $this->show($order->refresh());
    }
}
