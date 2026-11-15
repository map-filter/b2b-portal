<?php

namespace App\Services\Kaspi;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use App\Services\Orders\ChangeOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Принятый заказ Kaspi становится обычным заказом портала от контрагента «Kaspi.kz» и уходит в 1С тем же обменом.
 */
class KaspiCoreOrders
{
    public const COMPANY_EXTERNAL_ID = 'kaspi';

    public function __construct(private ChangeOrderStatus $changeStatus) {}

    public function mirror(KaspiOrder $kaspi): ?Order
    {
        if ($kaspi->order_id) {
            return $kaspi->order;
        }

        $entries = $kaspi->entries()->with('product')->get();
        $unmapped = $entries->whereNull('product_id');
        if ($unmapped->isNotEmpty()) {
            $problem = 'Не сопоставлены товары: '.$unmapped->pluck('sku')->map(fn ($sku) => $sku ?: 'без артикула')->implode(', ');
            $kaspi->update(['problem' => $problem]);
            $kaspi->log('error', "{$problem}. Заказ не отправлен в 1С");

            return null;
        }

        [$company, $contract, $user] = $this->channel();

        $order = DB::transaction(function () use ($kaspi, $entries, $company, $contract, $user) {
            $order = Order::create([
                'number' => 'KSP-'.$kaspi->code,
                'source' => 'kaspi',
                'company_id' => $company->id,
                'user_id' => $user->id,
                'contract_id' => $contract->id,
                'status' => OrderStatus::Confirmed,
                'total' => $kaspi->total_price,
                'comment' => $this->comment($kaspi),
                'delivery_address' => $kaspi->delivery_address,
                'desired_date' => $kaspi->planned_delivery_at?->toDateString(),
            ]);

            $order->items()->createMany($entries->map(fn ($entry) => [
                'product_id' => $entry->product_id,
                'sku' => $entry->product->sku,
                'name' => $entry->product->name,
                'unit' => $entry->product->unit,
                'quantity' => $entry->quantity,
                'price' => $entry->base_price,
                'total' => $entry->total_price,
            ])->all());

            $order->statusHistory()->create([
                'to_status' => OrderStatus::Confirmed,
                'comment' => "Принят в Kaspi, заказ {$kaspi->code}",
                'created_at' => now(),
            ]);

            $kaspi->update(['order_id' => $order->id, 'problem' => null]);

            return $order;
        });

        $kaspi->log('one_c', "Создан заказ {$order->number} для 1С");
        OrderPlaced::dispatch($order);

        return $order;
    }

    public function advance(KaspiOrder $kaspi, OrderStatus $target, string $comment): void
    {
        $order = $kaspi->order;
        if (! $order || $order->status === $target) {
            return;
        }

        if ($target === OrderStatus::Cancelled) {
            if ($order->status->canMoveTo(OrderStatus::Cancelled)) {
                $this->changeStatus->handle($order, OrderStatus::Cancelled, null, $comment);
            }

            return;
        }

        $chain = [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Assembling, OrderStatus::Shipped, OrderStatus::Completed];
        $from = array_search($order->status, $chain, true);
        $to = array_search($target, $chain, true);
        if ($from === false || $to === false || $from >= $to) {
            return;
        }

        foreach (array_slice($chain, $from + 1, $to - $from) as $step) {
            $this->changeStatus->handle($order, $step, null, $comment);
            $order->refresh();
        }
    }

    /**
     * @return array{Company, Contract, User}
     */
    public function channel(): array
    {
        $company = Company::firstOrCreate(['external_id' => self::COMPANY_EXTERNAL_ID], [
            'name' => 'Kaspi.kz',
            'legal_name' => 'Покупатели Kaspi Магазина',
            'bin' => 'KASPI0000000',
            'legal_address' => 'Заказы с маркетплейса Kaspi.kz',
        ]);

        $priceType = KaspiSettings::current()->price_type_id
            ?? PriceType::where('code', 'retail')->value('id')
            ?? PriceType::query()->value('id')
            ?? PriceType::create(['name' => 'Розничная', 'code' => 'retail'])->id;

        $contract = Contract::firstOrCreate(['company_id' => $company->id, 'number' => 'Kaspi Магазин'], [
            'price_type_id' => $priceType,
            'starts_at' => '2026-01-01',
            'is_default' => true,
        ]);

        $user = User::firstOrCreate(['email' => 'orders@kaspi.local'], [
            'company_id' => $company->id,
            'role' => UserRole::ClientBuyer,
            'name' => 'Покупатели Kaspi.kz',
            'password' => Str::random(40),
            'is_active' => false,
        ]);

        return [$company, $contract, $user];
    }

    private function comment(KaspiOrder $kaspi): string
    {
        return collect([
            "Kaspi {$kaspi->code}",
            $kaspi->deliveryLabel(),
            trim("{$kaspi->customer_name} +{$kaspi->customer_phone}"),
            $kaspi->planned_delivery_at ? 'доставка '.$kaspi->planned_delivery_at->timezone('Asia/Almaty')->format('d.m.Y') : null,
        ])->filter()->implode(', ');
    }
}
