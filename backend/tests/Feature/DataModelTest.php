<?php

use App\Enums\MealType;
use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a recipe belongs to a household and can have ingredients attached', function () {
    $household = Household::factory()->create();
    $recipe = Recipe::factory()->for($household)->create();
    $flour = Ingredient::factory()->create(['name' => 'Reisnudeln']);
    $sauce = Ingredient::factory()->create(['name' => 'Fischsauce']);

    $recipe->ingredients()->attach([
        $flour->id => ['quantity' => 250, 'unit' => 'g', 'notes' => null],
        $sauce->id => ['quantity' => 2, 'unit' => 'EL', 'notes' => 'nach Geschmack'],
    ]);

    expect($recipe->household->is($household))->toBeTrue()
        ->and($recipe->ingredients)->toHaveCount(2)
        ->and($recipe->ingredients->firstWhere('name', 'Reisnudeln')->pivot->quantity)->toEqual('250.00');
});

test('a meal plan aggregates calories per family member against their daily target', function () {
    $household = Household::factory()->create();

    $parent = FamilyMember::factory()->for($household)->create(['daily_calorie_target' => 2200]);
    $child = FamilyMember::factory()->for($household)->create(['daily_calorie_target' => 1600]);

    $recipe = Recipe::factory()->for($household)->create(['calories_per_serving' => 600]);

    $mealPlan = MealPlan::factory()
        ->for($household)
        ->for($recipe)
        ->create(['meal_type' => MealType::Dinner]);

    $mealPlan->familyMembers()->attach([
        $parent->id => ['portion_multiplier' => 1.0],
        $child->id => ['portion_multiplier' => 0.5],
    ]);

    $parentCalories = $parent->mealPlans()
        ->with('recipe')
        ->get()
        ->sum(fn (MealPlan $plan) => $plan->recipe->calories_per_serving * $plan->pivot->portion_multiplier);

    $childCalories = $child->mealPlans()
        ->with('recipe')
        ->get()
        ->sum(fn (MealPlan $plan) => $plan->recipe->calories_per_serving * $plan->pivot->portion_multiplier);

    expect($parentCalories)->toEqual(600.0)
        ->and($childCalories)->toEqual(300.0)
        ->and($parentCalories)->toBeLessThan($parent->daily_calorie_target)
        ->and($childCalories)->toBeLessThan($child->daily_calorie_target);
});
