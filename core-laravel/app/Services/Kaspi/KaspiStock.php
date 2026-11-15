<?php

namespace App\Services\Kaspi;

use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiStore;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KaspiStock
{
    /**
     * @return Collection<string, KaspiStore>
     */
    public function stores(): Collection
    {
        return KaspiStore::query()->where('is_active', true)->orderBy('id')->get()->keyBy('store_id');
    }

    public function warehouseFor(?string $storeId): ?int
    {
        $stores = $this->stores();

        return ($stores[$storeId ?? ''] ?? $stores->first())?->warehouse_id;
    }

    public function available(int $productId, ?string $storeId): float
    {
        $warehouse = $this->warehouseFor($storeId);

        return $warehouse ? (float) Stock::where('product_id', $productId)->where('warehouse_id', $warehouse)->value('quantity') : 0.0;
    }

    public function reserve(KaspiOrder $order): void
    {
        $this->move($order, -1);
        $order->update(['stock_reserved' => true]);
        $order->log('stock', 'Остаток списан под заказ');
    }

    public function release(KaspiOrder $order, string $reason): void
    {
        $this->move($order, 1);
        $order->update(['stock_reserved' => false]);
        $order->log('stock', "Остаток возвращён: {$reason}");
    }

    public function set(int $productId, int $warehouseId, float $quantity): void
    {
        Stock::updateOrCreate(['product_id' => $productId, 'warehouse_id' => $warehouseId], ['quantity' => max($quantity, 0)]);
    }

    private function move(KaspiOrder $order, int $sign): void
    {
        DB::transaction(function () use ($order, $sign) {
            foreach ($order->entries()->whereNotNull('product_id')->get() as $entry) {
                $warehouse = $this->warehouseFor($entry->store_id ?? $order->store_id);
                if (! $warehouse) {
                    continue;
                }

                $stock = Stock::where('product_id', $entry->product_id)->where('warehouse_id', $warehouse)->lockForUpdate()->first();
                $quantity = max(0, (float) ($stock?->quantity ?? 0) + $sign * $entry->quantity);

                Stock::updateOrCreate(['product_id' => $entry->product_id, 'warehouse_id' => $warehouse], ['quantity' => $quantity]);
            }
        });
    }
}
