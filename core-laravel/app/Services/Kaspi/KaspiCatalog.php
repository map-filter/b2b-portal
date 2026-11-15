<?php

namespace App\Services\Kaspi;

use App\Models\Kaspi\KaspiProduct;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Товары продавца на Kaspi: привязка к каталогу, цены, остатки по складам Kaspi, импорт из Excel.
 */
class KaspiCatalog
{
    private const MAX_ROWS = 5000;

    public function __construct(private KaspiStock $stock, private KaspiPriceList $priceList) {}

    public function list(string $filter, ?string $search, int $page): LengthAwarePaginator
    {
        if ($filter === 'unlinked') {
            $paginator = Product::query()->active()
                ->whereDoesntHave('kaspi')
                ->when($search, fn ($q) => $this->search($q, $search))
                ->orderBy('name')
                ->paginate(25, page: $page);

            return $paginator->setCollection($this->rows($paginator->getCollection()->map(fn (Product $product) => new KaspiProduct([
                'product_id' => $product->id,
                'sku' => $product->sku,
            ])->setRelation('product', $product))));
        }

        $stores = $this->stock->stores();
        $stockSum = Stock::query()->selectRaw('coalesce(sum(quantity), 0)')->whereColumn('stocks.product_id', 'kaspi_products.product_id')
            ->whereIn('warehouse_id', $stores->pluck('warehouse_id'));

        $paginator = KaspiProduct::query()
            ->with('product')
            ->select('kaspi_products.*')
            ->selectSub($stockSum, 'stock_total')
            ->when($filter === 'out_of_stock', fn ($q) => $q->where('is_published', true)->whereRaw('('.$stockSum->toSql().') <= 0', $stockSum->getBindings()))
            ->when($filter === 'unpublished', fn ($q) => $q->where('is_published', false))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('kaspi_products.sku', 'ilike', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $this->search($p, $search))))
            ->orderBy($filter === 'out_of_stock' ? 'kaspi_products.updated_at' : 'kaspi_products.sku', $filter === 'out_of_stock' ? 'desc' : 'asc')
            ->paginate(25, page: $page);

        return $paginator->setCollection($this->rows($paginator->getCollection()));
    }

    /**
     * @param  list<int>  $productIds
     */
    public function link(array $productIds): int
    {
        $products = Product::query()->whereIn('id', $productIds)->whereDoesntHave('kaspi')->get();

        foreach ($products as $product) {
            KaspiProduct::create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'price' => $this->retailPrice($product),
            ]);
        }

        return $products->count();
    }

    public function update(KaspiProduct $link, array $data): KaspiProduct
    {
        DB::transaction(function () use ($link, $data) {
            $link->fill(collect($data)->only(['price', 'is_published', 'sku'])->all())->save();

            if (isset($data['stocks']) && KaspiSettings::current()->standalone()) {
                $stores = $this->stock->stores();
                foreach ($data['stocks'] as $storeId => $quantity) {
                    if ($store = $stores[$storeId] ?? null) {
                        $this->stock->set($link->product_id, $store->warehouse_id, (float) $quantity);
                    }
                }
            }
        });

        return $link->refresh();
    }

    /**
     * Excel/CSV: «Артикул», «Цена», «Остаток» (необязательно «Склад Kaspi»). Новые артикулы из каталога сразу выставляются на Kaspi.
     *
     * @return array{updated: int, linked: int, not_found: list<array{row: int, sku: string}>, invalid: list<array{row: int, reason: string}>}
     */
    public function import(UploadedFile $file): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn ($cell) => Str::lower(trim((string) $cell)), $rows[0] ?? []);
        $column = fn (array $names, ?int $fallback) => collect($header)->search(fn ($cell) => collect($names)->contains(fn ($name) => str_contains($cell, $name))) ?: $fallback;

        $hasHeader = collect($header)->contains(fn ($cell) => str_contains($cell, 'артикул') || $cell === 'sku');
        $skuColumn = $hasHeader ? $column(['артикул', 'sku'], 0) : 0;
        $priceColumn = $hasHeader ? $column(['цена', 'price'], null) : 1;
        $stockColumn = $hasHeader ? $column(['остаток', 'количество', 'stock'], null) : 2;
        $storeColumn = $hasHeader ? $column(['склад', 'store'], null) : null;

        $standalone = KaspiSettings::current()->standalone();
        $stores = $this->stock->stores();
        $result = ['updated' => 0, 'linked' => 0, 'not_found' => [], 'invalid' => []];

        DB::transaction(function () use ($rows, $hasHeader, $skuColumn, $priceColumn, $stockColumn, $storeColumn, $standalone, $stores, &$result) {
            foreach (array_slice($rows, $hasHeader ? 1 : 0, self::MAX_ROWS, true) as $index => $row) {
                $sku = trim((string) ($row[$skuColumn] ?? ''));
                if ($sku === '') {
                    continue;
                }

                $link = KaspiProduct::whereRaw('upper(sku) = ?', [Str::upper($sku)])->first();
                if (! $link) {
                    $product = Product::whereRaw('upper(sku) = ?', [Str::upper($sku)])->first();
                    if (! $product) {
                        $result['not_found'][] = ['row' => $index + 1, 'sku' => $sku];

                        continue;
                    }
                    $link = KaspiProduct::create(['product_id' => $product->id, 'sku' => $product->sku, 'price' => $this->retailPrice($product)]);
                    $result['linked']++;
                }

                $price = $priceColumn !== null ? $this->number($row[$priceColumn] ?? null) : null;
                $stock = $stockColumn !== null ? $this->number($row[$stockColumn] ?? null) : null;

                if (($price !== null && $price <= 0) || ($stock !== null && $stock < 0)) {
                    $result['invalid'][] = ['row' => $index + 1, 'reason' => "{$sku}: цена должна быть больше нуля, остаток не меньше нуля"];

                    continue;
                }

                if ($price !== null) {
                    $link->update(['price' => $price]);
                }

                if ($stock !== null && $standalone) {
                    $storeId = $storeColumn !== null ? trim((string) ($row[$storeColumn] ?? '')) : '';
                    $store = $stores[$storeId] ?? $stores->first();
                    if ($store) {
                        $this->stock->set($link->product_id, $store->warehouse_id, $stock);
                    }
                }

                $result['updated']++;
            }
        });

        return $result;
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kaspi');
        $sheet->fromArray([['Артикул', 'Цена', 'Остаток', 'Склад Kaspi']]);

        $row = 2;
        foreach (KaspiProduct::with('product')->orderBy('sku')->limit(3000)->get() as $link) {
            $sheet->fromArray([[$link->sku, (float) $this->priceList->price($link), (float) $this->stock->available($link->product_id, null), $this->stock->stores()->keys()->first()]], null, "A{$row}");
            $row++;
        }

        foreach (['A' => 18, 'B' => 12, 'C' => 12, 'D' => 14] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        return $spreadsheet;
    }

    /**
     * @param  Collection<int, KaspiProduct>  $links
     */
    private function rows(Collection $links): Collection
    {
        $settings = KaspiSettings::current();
        $stores = $this->stock->stores();
        $productIds = $links->pluck('product_id');

        $stocks = Stock::whereIn('product_id', $productIds)->whereIn('warehouse_id', $stores->pluck('warehouse_id'))->get()->groupBy('product_id');
        $oneCPrices = ! $settings->standalone() && $settings->price_type_id
            ? ProductPrice::whereIn('product_id', $productIds)->where('price_type_id', $settings->price_type_id)->pluck('price', 'product_id')
            : collect();
        $sold = DB::table('kaspi_order_entries')
            ->join('kaspi_orders', 'kaspi_orders.id', '=', 'kaspi_order_entries.kaspi_order_id')
            ->whereIn('product_id', $productIds)
            ->where('kaspi_orders.kaspi_created_at', '>=', now()->subDays(30))
            ->whereNotIn('kaspi_orders.status', ['CANCELLED', 'RETURNED'])
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(quantity) as quantity')
            ->pluck('quantity', 'product_id');

        return $links->map(function (KaspiProduct $link) use ($stores, $stocks, $oneCPrices, $sold) {
            $product = $link->product;
            $perStore = $stores->mapWithKeys(fn ($store) => [
                $store->store_id => (float) (($stocks[$link->product_id] ?? collect())->firstWhere('warehouse_id', $store->warehouse_id)?->quantity ?? 0),
            ]);
            $oneC = $oneCPrices[$link->product_id] ?? null;

            return [
                'id' => $link->id,
                'product_id' => $link->product_id,
                'sku' => $link->sku,
                'name' => $product->name,
                'brand' => $product->brand,
                'unit' => $product->unit,
                'base_price' => $product->base_price,
                'own_price' => $link->price,
                'price' => $this->priceList->price($link, $oneC),
                'price_source' => $oneC !== null ? '1c' : 'manual',
                'is_published' => $link->exists ? $link->is_published : false,
                'linked' => $link->exists,
                'stocks' => $perStore,
                'stock_total' => $perStore->sum(),
                'sold_30d' => (int) ($sold[$link->product_id] ?? 0),
                'master_id' => $link->kaspi_master_id,
            ];
        })->values();
    }

    private function search($query, string $search)
    {
        return $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")->orWhere('brand', 'ilike', "%{$search}%"));
    }

    private function retailPrice(Product $product): string
    {
        $retail = ProductPrice::where('product_id', $product->id)
            ->whereHas('priceType', fn ($q) => $q->where('code', 'retail'))
            ->value('price');

        return (string) (ceil((float) ($retail ?? $product->base_price) / 10) * 10);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $value);

        return is_numeric($normalized) ? (float) $normalized : -1;
    }
}
