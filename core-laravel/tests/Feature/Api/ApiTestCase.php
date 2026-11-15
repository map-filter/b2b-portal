<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Contract;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function clientCompany(array $company = [], float $discount = 10): Company
    {
        $company = Company::factory()->create($company);

        Contract::create([
            'company_id' => $company->id,
            'price_type_id' => PriceType::firstOrCreate(['code' => 'retail'], ['name' => 'Розничная'])->id,
            'number' => 'Д-1',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
            'discount_percent' => $discount,
            'is_default' => true,
        ]);

        return $company;
    }

    protected function buyer(?Company $company = null, UserRole $role = UserRole::ClientBuyer): User
    {
        return User::factory()->create([
            'company_id' => ($company ?? $this->clientCompany())->id,
            'role' => $role,
        ]);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['base_price' => 1000, 'min_quantity' => 1]);
    }
}
