<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CartImportController extends Controller
{
    public function __construct(private CartImporter $importer) {}

    /**
     * Загрузить заказ из Excel или CSV: колонки «Артикул» и «Количество».
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'],
        ]);

        return response()->json($this->importer->import($request->user(), $request->file('file')));
    }

    public function template(): StreamedResponse
    {
        $writer = new Xlsx($this->importer->template());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'zakaz-shablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
