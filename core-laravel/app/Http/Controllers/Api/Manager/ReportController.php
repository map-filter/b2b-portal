<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Сводка продаж за период: итоги, выручка по дням, статусы, топ товаров и клиентов.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90,365'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subDays($request->integer('days', 30) - 1)->startOfDay();
        $report = new SalesReport($from, $to, $request->boolean('mine') ? $request->user()->id : null);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $report->summary(),
            'daily' => $report->daily(),
            'by_status' => $report->byStatus(),
            'top_products' => $report->topProducts(),
            'top_companies' => $report->topCompanies(),
        ]);
    }
}
