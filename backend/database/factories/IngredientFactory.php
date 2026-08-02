<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->word()),
            'default_unit' => $this->faker->randomElement(['g', 'ml', 'Stück', 'EL', 'TL']),
            'calories_per_100g' => $this->faker->randomFloat(2, 20, 900),
        ];
    }
}
