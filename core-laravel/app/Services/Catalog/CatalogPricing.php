<?php

namespace App\Services\Catalog;

use App\Models\Contract;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use Illuminate\Support\Collection;

class CatalogPricing
{
    public function __construct(private PriceResolver $resolver) {}

    public function contractFor(User $user): ?Contract
    {
        return $user->company?->defaultContract;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public function apply(User $user, Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $contract = $this->contractFor($user);
        $prices = $contract ? $this->resolver->forContract($contract, $products) : collect();

        return $products->each(fn (Product $product) => $product->setAttribute(
            'contract_price',
            $prices->get($product->id, (string) $product->base_price),
        ));
    }
}
