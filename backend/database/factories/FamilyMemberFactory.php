<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FamilyMember>
 */
class FamilyMemberFactory extends Factory
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
            'name' => $this->faker->firstName(),
            'daily_calorie_target' => $this->faker->numberBetween(1400, 2800),
            'daily_protein_target_g' => $this->faker->numberBetween(50, 150),
        ];
    }
}
