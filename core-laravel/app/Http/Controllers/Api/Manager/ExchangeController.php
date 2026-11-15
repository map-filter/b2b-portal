<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\ExchangeImport;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ExchangeController extends Controller
{
    /**
     * Журнал загрузок каталога из 1С и сводка по товарам из 1С.
     */
    public function imports(): JsonResponse
    {
        return response()->json([
            'data' => ExchangeImport::latest('id')->limit(30)->get()->map(fn (ExchangeImport $import) => [
                'id' => $import->import_id,
                'file' => $import->file,
                'size_bytes' => $import->size_bytes,
                'duration_ms' => $import->duration_ms,
                'stats' => $import->stats,
                'applied' => $import->applied,
                'finished_at' => $import->finished_at?->toIso8601String(),
                'created_at' => $import->created_at->toIso8601String(),
            ]),
            'products_from_1c' => Product::whereNotNull('external_id')->count(),
            'endpoint' => rtrim(config('app.url'), '/').'/1c_exchange.php',
        ]);
    }
}
