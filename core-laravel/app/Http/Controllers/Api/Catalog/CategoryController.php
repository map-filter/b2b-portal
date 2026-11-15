<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * Дерево категорий с количеством активных товаров.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->with(['children' => fn ($query) => $query->withCount(['products' => fn ($q) => $q->active()])])
            ->get();

        return CategoryResource::collection($categories);
    }
}
