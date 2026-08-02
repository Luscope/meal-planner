<?php

namespace Database\Factories;

use App\Enums\RecipeSourceType;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => ucfirst($this->faker->words(3, true)),
            'cuisine' => $this->faker->randomElement(['vietnamesisch', 'japanisch', 'thailändisch', 'italienisch', 'deutsch']),
            'description' => $this->faker->sentence(),
            'instructions' => $this->faker->sentences(4),
            'servings' => $this->faker->numberBetween(2, 6),
            'prep_time_minutes' => $this->faker->numberBetween(5, 30),
            'cook_time_minutes' => $this->faker->numberBetween(10, 60),
            'calories_per_serving' => $this->faker->numberBetween(300, 900),
            'protein_per_serving_g' => $this->faker->randomFloat(2, 10, 60),
            'carbs_per_serving_g' => $this->faker->randomFloat(2, 10, 100),
            'fat_per_serving_g' => $this->faker->randomFloat(2, 5, 50),
            'source_type' => RecipeSourceType::Text,
        ];
    }
}
