<?php

namespace App\Services\Exchange;

use App\Models\Category;
use App\Models\ExchangeImport;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CatalogSync
{
    private const FALLBACK_CATEGORY = 'Без категории (1С)';

    private const PRICE_TYPE_CODES = ['Розничная' => 'retail', 'Оптовая' => 'wholesale', 'Крупный опт' => 'large_wholesale'];

    public function handle(string $routingKey, array $message): void
    {
        $items = $message['items'] ?? [];
        $importId = $message['import_id'] ?? null;

        $applied = match ($routingKey) {
            'catalog.categories' => $this->categories($items),
            'catalog.products' => $this->products($items),
            'catalog.price_types' => $this->priceTypes($items),
            'catalog.warehouses' => $this->warehouses($items),
            'catalog.prices' => $this->prices($items),
            'catalog.stocks' => $this->stocks($items),
            'catalog.import_finished' => $this->finished($message),
            default => throw new InvalidArgumentException("Неизвестный тип сообщения {$routingKey}"),
        };

        if ($importId && $routingKey !== 'catalog.import_finished') {
            $this->count($importId, Str::after($routingKey, 'catalog.'), $applied);
        }
    }

    private function categories(array $items): int
    {
        foreach ($items as $item) {
            $category = Category::firstOrNew(['external_id' => $item['external_id']]);
            $category->name = $item['name'];
            $category->slug ??= $this->uniqueSlug('categories', $item['name'], $item['external_id']);
            $category->parent_id = empty($item['parent_external_id'])
                ? null
                : Category::where('external_id', $item['parent_external_id'])->value('id');
            $category->save();
        }

        return count($items);
    }

    private function products(array $items): int
    {
        $categories = Category::whereIn('external_id', array_filter(array_column($items, 'category_external_id')))
            ->pluck('id', 'external_id');
        $fallback = null;

        $existingByExternal = Product::whereIn('external_id', array_column($items, 'external_id'))->get()->keyBy('external_id');
        $existingBySku = Product::whereIn('sku', array_filter(array_column($items, 'sku')))->whereNull('external_id')->get()->keyBy('sku');

        DB::transaction(function () use ($items, $categories, &$fallback, $existingByExternal, $existingBySku) {
            foreach ($items as $item) {
                $product = $existingByExternal[$item['external_id']]
                    ?? $existingBySku[$item['sku'] ?? ''] ?? new Product(['base_price' => 0]);

                $categoryId = $categories[$item['category_external_id'] ?? ''] ?? null;
                if (! $categoryId) {
                    $fallback ??= Category::firstOrCreate(['external_id' => 'fallback'], [
                        'name' => self::FALLBACK_CATEGORY,
                        'slug' => 'bez-kategorii-1c',
                    ])->id;
                    $categoryId = $fallback;
                }

                $product->fill([
                    'external_id' => $item['external_id'],
                    'sku' => $item['sku'] ?: Str::limit($item['external_id'], 60, ''),
                    'name' => $item['name'],
                    'category_id' => $categoryId,
                    'unit' => Str::limit($item['unit'] ?: 'шт', 16, ''),
                    'brand' => $item['brand'] ?? null,
                    'description' => $item['description'] ?? $product->description,
                    'specs' => ($item['specs'] ?? []) ?: ($product->specs ?? []),
                    'is_active' => ! ($item['deleted'] ?? false),
                ]);
                $product->slug ??= $this->uniqueSlug('products', $item['name'], $item['external_id']);
                $product->save();
            }
        });

        return count($items);
    }

    private function priceTypes(array $items): int
    {
        foreach ($items as $item) {
            $type = PriceType::where('external_id', $item['external_id'])->first()
                ?? PriceType::whereNull('external_id')->where('name', $item['name'])->first()
                ?? new PriceType(['code' => self::PRICE_TYPE_CODES[$item['name']] ?? Str::slug($item['name'], '_').'_'.Str::lower(Str::random(4))]);

            $type->fill(['name' => $item['name'], 'external_id' => $item['external_id']])->save();
        }

        return count($items);
    }

    private function warehouses(array $items): int
    {
        foreach ($items as $item) {
            $warehouse = Warehouse::where('external_id', $item['external_id'])->first()
                ?? Warehouse::whereNull('external_id')->where('name', $item['name'])->first()
                ?? new Warehouse;

            $warehouse->fill(['name' => $item['name'], 'external_id' => $item['external_id']])->save();
        }

        return count($items);
    }

    private function prices(array $items): int
    {
        $products = $this->productIds($items);
        $types = PriceType::whereIn('external_id', array_column($items, 'price_type_external_id'))->get()->keyBy('external_id');
        $now = now();

        $rows = collect($items)
            ->filter(fn ($item) => isset($products[$item['product_external_id']], $types[$item['price_type_external_id']]))
            ->map(fn ($item) => [
                'product_id' => $products[$item['product_external_id']],
                'price_type_id' => $types[$item['price_type_external_id']]->id,
                'price' => $item['price'],
                'updated_at' => $now,
            ]);

        DB::transaction(function () use ($rows, $types) {
            DB::table('product_prices')->upsert($rows->values()->all(), ['product_id', 'price_type_id'], ['price', 'updated_at']);

            $retail = $types->firstWhere('code', 'retail');
            if ($retail) {
                $rows->where('price_type_id', $retail->id)->each(fn ($row) => DB::table('products')
                    ->where('id', $row['product_id'])
                    ->update(['base_price' => $row['price'], 'updated_at' => $row['updated_at']]));
            }
        });

        return $rows->count();
    }

    private function stocks(array $items): int
    {
        $products = $this->productIds($items);
        $warehouses = Warehouse::whereIn('external_id', array_column($items, 'warehouse_external_id'))->pluck('id', 'external_id');
        $now = now();

        $rows = collect($items)
            ->filter(fn ($item) => isset($products[$item['product_external_id']], $warehouses[$item['warehouse_external_id']]))
            ->map(fn ($item) => [
                'product_id' => $products[$item['product_external_id']],
                'warehouse_id' => $warehouses[$item['warehouse_external_id']],
                'quantity' => max(0, (float) $item['quantity']),
                'updated_at' => $now,
            ]);

        DB::table('stocks')->upsert($rows->values()->all(), ['product_id', 'warehouse_id'], ['quantity', 'updated_at']);

        return $rows->count();
    }

    private function finished(array $report): int
    {
        ExchangeImport::updateOrCreate(['import_id' => $report['id']], [
            'file' => $report['file'],
            'size_bytes' => $report['size_bytes'] ?? 0,
            'duration_ms' => $report['duration_ms'] ?? 0,
            'stats' => $report['stats'] ?? [],
            'finished_at' => now(),
        ]);

        return 0;
    }

    private function count(string $importId, string $kind, int $applied): void
    {
        $import = ExchangeImport::firstOrCreate(['import_id' => $importId], ['file' => '...']);
        $counts = $import->applied ?? [];
        $counts[$kind] = ($counts[$kind] ?? 0) + $applied;
        $import->update(['applied' => $counts]);
    }

    /**
     * @return Collection<string, int>
     */
    private function productIds(array $items): Collection
    {
        return Product::whereIn('external_id', array_unique(array_column($items, 'product_external_id')))->pluck('id', 'external_id');
    }

    private function uniqueSlug(string $table, string $name, string $externalId): string
    {
        $slug = Str::slug($name) ?: 'item';

        return DB::table($table)->where('slug', $slug)->exists() ? $slug.'-'.substr(md5($externalId), 0, 6) : $slug;
    }
}
