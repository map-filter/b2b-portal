<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContractPrice;
use App\Models\Product;
use App\Services\Pricing\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContractPriceController extends Controller
{
    /**
     * Индивидуальные цены договора компании в сравнении с ценой по виду цен.
     */
    public function index(Company $company): JsonResponse
    {
        $contract = $company->defaultContract()->firstOrFail();
        $prices = ContractPrice::where('contract_id', $contract->id)->pluck('price', 'product_id');
        $products = Product::whereIn('id', $prices->keys())->orderBy('name')->get();

        $standard = app(PriceResolver::class)->forContract($contract, $products, withContractPrices: false);

        return response()->json(['data' => $products->map(fn (Product $product) => [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'base_price' => $product->base_price,
            'standard_price' => $standard[$product->id],
            'price' => $prices[$product->id],
        ])]);
    }

    public function upsert(Request $request, Company $company, Product $product): JsonResponse
    {
        $data = $request->validate(['price' => ['required', 'numeric', 'min:0.01', 'max:999999999']]);
        $contract = $company->defaultContract()->firstOrFail();

        ContractPrice::updateOrCreate(
            ['contract_id' => $contract->id, 'product_id' => $product->id],
            ['price' => $data['price']],
        );

        return $this->index($company);
    }

    public function destroy(Company $company, Product $product): Response
    {
        $contract = $company->defaultContract()->firstOrFail();
        ContractPrice::where('contract_id', $contract->id)->where('product_id', $product->id)->delete();

        return response()->noContent();
    }
}
