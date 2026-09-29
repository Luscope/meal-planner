<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MealPlan>
 */
class MealPlanFactory extends Factory
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
            'recipe_id' => Recipe::factory(),
            'date' => $this->faker->dateTimeBetween('now', '+2 weeks')->format('Y-m-d'),
            'meal_type' => $this->faker->randomElement(MealType::cases()),
            'planned_servings' => $this->faker->numberBetween(2, 6),
        ];
    }
}
