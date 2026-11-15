<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CatalogPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private CatalogPricing $pricing) {}

    /**
     * Каталог с ценами по договору клиента.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'brand' => ['nullable', 'array'],
            'brand.*' => ['string'],
            'specs' => ['nullable', 'array'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,price_asc,price_desc,sku'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filtered($request)
            ->with('category')
            ->withSum('stocks as stock_total', 'quantity');

        match ($request->input('sort', 'sku')) {
            'price_asc' => $query->orderBy('base_price'),
            'price_desc' => $query->orderByDesc('base_price'),
            'name' => $query->orderBy('name'),
            default => $query->orderBy('sku'),
        };

        $page = $query->paginate($request->integer('per_page', 24))->withQueryString();
        $this->pricing->apply($request->user(), $page->getCollection());

        return ProductResource::collection($page);
    }

    /**
     * Значения для фильтров: бренды и характеристики в выбранной категории.
     */
    public function facets(Request $request): JsonResponse
    {
        $products = $this->filtered($request, withSelection: false)->get(['id', 'brand', 'specs']);

        $specs = [];
        foreach ($products as $product) {
            foreach ($product->specs ?? [] as $key => $value) {
                $specs[$key][(string) $value] = true;
            }
        }

        $specs = collect($specs)
            ->map(fn (array $values) => collect(array_keys($values))->map(fn ($value) => (string) $value)->sort(SORT_NATURAL)->values())
            ->filter(fn ($values) => $values->count() > 1);

        return response()->json([
            'brands' => $products->pluck('brand')->filter()->unique()->sort()->values(),
            'specs' => $specs,
            'total' => $products->count(),
        ]);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'stocks.warehouse'])->loadSum('stocks as stock_total', 'quantity');
        $this->pricing->apply($request->user(), collect([$product]));

        return new ProductResource($product);
    }

    private function filtered(Request $request, bool $withSelection = true): Builder
    {
        return Product::query()
            ->active()
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')).'%';
                $query->where(fn (Builder $q) => $q->where('name', 'ilike', $term)->orWhere('sku', 'ilike', $term));
            })
            ->when($request->filled('category'), function (Builder $query) use ($request) {
                $id = $request->integer('category');
                $ids = Category::where('parent_id', $id)->pluck('id')->push($id);
                $query->whereIn('category_id', $ids);
            })
            ->when($withSelection && $request->filled('brand'), fn (Builder $query) => $query->whereIn('brand', (array) $request->input('brand')))
            ->when($request->boolean('in_stock'), fn (Builder $query) => $query->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0)))
            ->when($withSelection && is_array($request->input('specs')), function (Builder $query) use ($request) {
                foreach (array_filter($request->input('specs'), fn ($value) => $value !== null && $value !== '') as $key => $value) {
                    $query->whereRaw('specs ->> ? = ?', [$key, (string) $value]);
                }
            });
    }
}
