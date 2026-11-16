<?php

namespace App\Services\Kaspi;

use App\Enums\Kaspi\KaspiDeliveryMode;
use App\Enums\Kaspi\KaspiState;
use App\Models\Kaspi\KaspiOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class KaspiReport
{
    private const NOT_SALES = ['CANCELLED', 'CANCELLING', 'RETURNED'];

    public function __construct(private KaspiCatalog $catalog) {}

    public function build(int $days): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();
        $previousFrom = $from->subDays($days);

        $sales = fn (CarbonImmutable $a, CarbonImmutable $b) => KaspiOrder::query()
            ->whereBetween('kaspi_created_at', [$a, $b])
            ->whereNotIn('status', self::NOT_SALES);

        $row = $sales($from, $to)->selectRaw('count(*) as orders, coalesce(sum(total_price), 0) as revenue, coalesce(sum(delivery_cost_for_seller), 0) as delivery')->first();
        $all = KaspiOrder::whereBetween('kaspi_created_at', [$from, $to])->count();
        $cancelled = KaspiOrder::whereBetween('kaspi_created_at', [$from, $to])->whereIn('status', self::NOT_SALES)->count();

        return [
            'summary' => [
                'revenue' => $this->money($row->revenue),
                'previous_revenue' => $this->money($sales($previousFrom, $from->subSecond())->sum('total_price')),
                'orders' => (int) $row->orders,
                'average' => $row->orders ? $this->money($row->revenue / $row->orders) : '0.00',
                'delivery_cost' => $this->money($row->delivery),
                'cancel_rate' => $all ? round($cancelled / $all * 100, 1) : 0,
                'awaiting' => KaspiOrder::where('state', KaspiState::New)->where('status', 'APPROVED_BY_BANK')->count(),
                'problems' => KaspiOrder::whereNotNull('problem')->whereNotIn('status', self::NOT_SALES)->count(),
            ],
            'daily' => $this->daily($sales($from, $to), $from, $to),
            'by_delivery' => $this->byDelivery($sales($from, $to)),
            'top_products' => $this->topProducts($from, $to),
            'out_of_stock' => $this->catalog->list('out_of_stock', null, 1)->getCollection()
                ->sortByDesc('sold_30d')->take(8)->values(),
        ];
    }

    private function daily(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $query->selectRaw("to_char(date_trunc('day', kaspi_created_at at time zone 'UTC' at time zone 'Asia/Almaty'), 'YYYY-MM-DD') as day, count(*) as orders, sum(total_price) as revenue")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($day = $from->setTimezone('Asia/Almaty')->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'orders' => (int) ($rows[$key]->orders ?? 0), 'revenue' => $this->money($rows[$key]->revenue ?? 0)];
        }

        return $series;
    }

    private function byDelivery(Builder $query): array
    {
        $labels = ['kaspi_delivery' => 'Kaspi Доставка', 'pickup' => 'Самовывоз', 'own_delivery' => 'Своя доставка'];

        return $query->selectRaw("case when delivery_mode = ? then 'pickup' when is_kaspi_delivery then 'kaspi_delivery' else 'own_delivery' end as channel, count(*) as orders, sum(total_price) as revenue", [KaspiDeliveryMode::Pickup->value])
            ->groupBy('channel')
            ->orderByDesc('orders')
            ->get()
            ->map(fn ($row) => ['channel' => $row->channel, 'label' => $labels[$row->channel], 'orders' => (int) $row->orders, 'revenue' => $this->money($row->revenue)])
            ->all();
    }

    private function topProducts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('kaspi_order_entries as e')
            ->join('kaspi_orders as o', 'o.id', '=', 'e.kaspi_order_id')
            ->whereBetween('o.kaspi_created_at', [$from, $to])
            ->whereNotIn('o.status', self::NOT_SALES)
            ->groupBy('e.sku', 'e.name', 'e.product_id')
            ->selectRaw('e.sku, e.name, e.product_id, sum(e.quantity) as quantity, sum(e.total_price) as revenue')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['sku' => $row->sku, 'name' => $row->name, 'product_id' => $row->product_id, 'quantity' => (int) $row->quantity, 'revenue' => $this->money($row->revenue)])
            ->all();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
