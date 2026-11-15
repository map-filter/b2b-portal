<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => 'ТОО «'.$name.'»',
            'bin' => fake()->unique()->numerify('############'),
            'legal_address' => fake()->address(),
            'phone' => fake()->numerify('+7 7## ### ## ##'),
            'email' => fake()->unique()->companyEmail(),
            'credit_limit' => fake()->randomElement([0, 500000, 1000000, 3000000]),
            'payment_delay_days' => fake()->randomElement([0, 7, 14, 30]),
        ];
    }
}
