<?php

namespace App\Http\Controllers\Api\Manager\Kaspi;

use App\Http\Controllers\Controller;
use App\Models\Kaspi\KaspiProduct;
use App\Models\Kaspi\KaspiSettings;
use App\Services\Kaspi\KaspiCatalog;
use App\Services\Kaspi\KaspiStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KaspiProductController extends Controller
{
    public function __construct(private KaspiCatalog $catalog) {}

    /**
     * Товары на Kaspi. filter: all, out_of_stock (выставлены, но остатка нет), unpublished, unlinked (есть в каталоге, нет на Kaspi).
     */
    public function index(Request $request, KaspiStock $stock): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'out_of_stock', 'unpublished', 'unlinked'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->catalog->list($data['filter'] ?? 'all', $data['q'] ?? null, (int) ($data['page'] ?? 1));
        $settings = KaspiSettings::current();

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'mode' => $settings->mode->value,
                'price_from_1c' => ! $settings->standalone() && (bool) $settings->price_type_id,
                'stores' => $stock->stores()->values()->map(fn ($s) => ['store_id' => $s->store_id, 'name' => $s->name]),
                'counts' => [
                    'all' => KaspiProduct::count(),
                    'out_of_stock' => $this->catalog->list('out_of_stock', null, 1)->total(),
                    'unpublished' => KaspiProduct::where('is_published', false)->count(),
                    'unlinked' => $this->catalog->list('unlinked', null, 1)->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        return response()->json(['linked' => $this->catalog->link($data['product_ids'])], 201);
    }

    public function update(Request $request, KaspiProduct $kaspiProduct): JsonResponse
    {
        $data = $request->validate([
            'price' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:100000000'],
            'is_published' => ['sometimes', 'boolean'],
            'sku' => ['sometimes', 'string', 'max:64', Rule::unique('kaspi_products', 'sku')->ignore($kaspiProduct->id)],
            'stocks' => ['sometimes', 'array'],
            'stocks.*' => ['numeric', 'min:0', 'max:1000000'],
        ]);

        if (isset($data['stocks']) && ! KaspiSettings::current()->standalone()) {
            abort(422, 'В режиме с 1С остатки приходят из 1С');
        }

        $this->catalog->update($kaspiProduct, $data);

        return response()->json(['ok' => true]);
    }

    public function destroy(KaspiProduct $kaspiProduct): JsonResponse
    {
        $kaspiProduct->delete();

        return response()->json(null, 204);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt']]);

        return response()->json($this->catalog->import($request->file('file')));
    }

    public function template(): StreamedResponse
    {
        $writer = new Xlsx($this->catalog->template());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'kaspi-tovary.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
