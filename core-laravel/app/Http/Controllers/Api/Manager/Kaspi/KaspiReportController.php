<?php

namespace App\Http\Controllers\Api\Manager\Kaspi;

use App\Http\Controllers\Controller;
use App\Services\Kaspi\KaspiReport;
use Illuminate\Http\Request;

class KaspiReportController extends Controller
{
    /**
     * Продажи на Kaspi за период: выручка, заказы по дням, способы доставки, топ товаров, товары без остатка.
     */
    public function __invoke(Request $request, KaspiReport $report): array
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90,365']]);

        return $report->build((int) ($data['days'] ?? 30));
    }
}
