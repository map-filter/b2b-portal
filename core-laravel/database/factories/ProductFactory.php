<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::ucfirst(fake()->unique()->words(3, true));

        return [
            'category_id' => Category::factory(),
            'sku' => fake()->unique()->bothify('EO-####-??'),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'brand' => fake()->randomElement(['ВольтПро', 'Светоч', 'КабельСтандарт']),
            'unit' => 'шт',
            'base_price' => fake()->randomFloat(2, 100, 50000),
            'specs' => [],
        ];
    }
}
