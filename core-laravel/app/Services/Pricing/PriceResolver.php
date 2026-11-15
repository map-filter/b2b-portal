<?php

namespace App\Services\Pricing;

use App\Models\Contract;
use App\Models\ContractPrice;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Collection;

class PriceResolver
{
    /**
     * Цена по договору: индивидуальная цена договора, иначе цена его вида цен со скидкой, иначе базовая со скидкой.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, string>
     */
    public function forContract(Contract $contract, Collection $products, bool $withContractPrices = true): Collection
    {
        $ids = $products->pluck('id');

        $contractPrices = $withContractPrices
            ? ContractPrice::query()->where('contract_id', $contract->id)->whereIn('product_id', $ids)->pluck('price', 'product_id')
            : collect();

        $typePrices = ProductPrice::query()
            ->where('price_type_id', $contract->price_type_id)
            ->whereIn('product_id', $ids)
            ->pluck('price', 'product_id');

        $multiplier = bcsub('1', bcdiv((string) $contract->discount_percent, '100', 4), 4);

        return $products->mapWithKeys(function (Product $product) use ($contractPrices, $typePrices, $multiplier) {
            if ($contractPrices->has($product->id)) {
                return [$product->id => $this->money($contractPrices[$product->id])];
            }

            $price = $typePrices[$product->id] ?? $product->base_price;

            return [$product->id => $this->money(bcmul((string) $price, $multiplier, 4))];
        });
    }

    public function forProduct(Contract $contract, Product $product): string
    {
        return $this->forContract($contract, collect([$product]))->get($product->id);
    }

    private function money(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
