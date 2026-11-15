<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesReport
{
    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        private ?int $managerId = null,
    ) {}

    public function summary(): array
    {
        $row = $this->orders()
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue, count(distinct company_id) as companies')
            ->first();

        $previous = new self($this->from->sub($this->to->diffAsCarbonInterval($this->from)), $this->from->subSecond(), $this->managerId);
        $previousRevenue = (string) $previous->orders()->sum('total');

        return [
            'orders' => (int) $row->orders,
            'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            'average' => $row->orders ? number_format($row->revenue / $row->orders, 2, '.', '') : '0.00',
            'companies' => (int) $row->companies,
            'previous_revenue' => number_format((float) $previousRevenue, 2, '.', ''),
            'awaiting' => (int) $this->base()->where('orders.status', OrderStatus::New->value)->count(),
        ];
    }

    public function daily(): array
    {
        $rows = $this->orders()
            ->selectRaw("to_char(date_trunc('day', orders.created_at), 'YYYY-MM-DD') as day, count(*) as orders, sum(total) as revenue")
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $series = [];
        for ($day = $this->from->startOfDay(); $day <= $this->to; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'revenue' => number_format((float) ($rows[$key] ?? 0), 2, '.', '')];
        }

        return $series;
    }

    public function byStatus(): array
    {
        return $this->base()
            ->whereBetween('orders.created_at', [$this->from, $this->to])
            ->selectRaw('orders.status, count(*) as orders, sum(total) as revenue')
            ->groupBy('orders.status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'label' => OrderStatus::from($row->status)->label(),
                'orders' => (int) $row->orders,
                'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            ])
            ->all();
    }

    public function topProducts(int $limit = 10): array
    {
        return $this->orders()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('order_items.product_id, order_items.sku, max(order_items.name) as name, sum(order_items.quantity) as quantity, max(order_items.unit) as unit, sum(order_items.total) as revenue')
            ->groupBy('order_items.product_id', 'order_items.sku')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'sku' => $row->sku,
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'unit' => $row->unit,
                'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            ])
            ->all();
    }

    public function topCompanies(int $limit = 10): array
    {
        return $this->orders()
            ->join('companies', 'companies.id', '=', 'orders.company_id')
            ->selectRaw('companies.id, companies.name, count(*) as orders, sum(orders.total) as revenue')
            ->groupBy('companies.id', 'companies.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            ])
            ->all();
    }

    private function base(): Builder
    {
        return DB::table('orders')
            ->when($this->managerId, fn (Builder $query) => $query
                ->whereIn('orders.company_id', DB::table('companies')->where('manager_id', $this->managerId)->select('id')));
    }

    private function orders(): Builder
    {
        return $this->base()
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$this->from, $this->to]);
    }
}
