<?php

use App\Enums\MealType;
use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function planningHousehold(): array
{
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();
    Sanctum::actingAs($user);

    return [$household, $user];
}

test('creates a meal plan with assigned family members', function () {
    [$household] = planningHousehold();
    $recipe = Recipe::factory()->for($household)->create(['servings' => 4]);
    $parent = FamilyMember::factory()->for($household)->create();
    $child = FamilyMember::factory()->for($household)->create();

    $response = $this->postJson('/api/meal-plans', [
        'recipe_id' => $recipe->id,
        'date' => '2026-07-20',
        'meal_type' => MealType::Dinner->value,
        'family_members' => [
            ['family_member_id' => $parent->id, 'portion_multiplier' => 1.0],
            ['family_member_id' => $child->id, 'portion_multiplier' => 0.5],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.planned_servings', '4.00')
        ->assertJsonCount(2, 'data.family_members');

    expect(MealPlan::first()->familyMembers)->toHaveCount(2);
});

test('rejects a duplicate meal plan for the same day, meal type and recipe', function () {
    [$household] = planningHousehold();
    $recipe = Recipe::factory()->for($household)->create();

    $payload = [
        'recipe_id' => $recipe->id,
        'date' => '2026-07-20',
        'meal_type' => MealType::Lunch->value,
    ];

    $this->postJson('/api/meal-plans', $payload)->assertCreated();
    $this->postJson('/api/meal-plans', $payload)->assertStatus(422);
});

test('rejects a family member from another household', function () {
    [$household] = planningHousehold();
    $otherHousehold = Household::factory()->create();
    $recipe = Recipe::factory()->for($household)->create();
    $foreignMember = FamilyMember::factory()->for($otherHousehold)->create();

    $response = $this->postJson('/api/meal-plans', [
        'recipe_id' => $recipe->id,
        'date' => '2026-07-20',
        'meal_type' => MealType::Breakfast->value,
        'family_members' => [
            ['family_member_id' => $foreignMember->id],
        ],
    ]);

    $response->assertStatus(422);
});

test('updates planned servings and re-syncs family members', function () {
    [$household] = planningHousehold();
    $recipe = Recipe::factory()->for($household)->create();
    $mealPlan = MealPlan::factory()->for($household)->for($recipe)->create(['planned_servings' => 2]);
    $member = FamilyMember::factory()->for($household)->create();

    $response = $this->patchJson("/api/meal-plans/{$mealPlan->id}", [
        'planned_servings' => 6,
        'family_members' => [
            ['family_member_id' => $member->id, 'portion_multiplier' => 1.5],
        ],
    ]);

    $response->assertOk()->assertJsonPath('data.planned_servings', '6.00');

    expect($mealPlan->fresh()->familyMembers)->toHaveCount(1)
        ->and((float) $mealPlan->fresh()->familyMembers->first()->pivot->portion_multiplier)->toBe(1.5);
});

test('updates the assigned recipe of an existing meal plan entry', function () {
    [$household] = planningHousehold();
    $originalRecipe = Recipe::factory()->for($household)->create(['servings' => 2]);
    $newRecipe = Recipe::factory()->for($household)->create(['servings' => 4]);
    $mealPlan = MealPlan::factory()->for($household)->for($originalRecipe)->create();

    $response = $this->patchJson("/api/meal-plans/{$mealPlan->id}", [
        'recipe_id' => $newRecipe->id,
    ]);

    $response->assertOk()->assertJsonPath('data.recipe.id', $newRecipe->id);

    expect($mealPlan->fresh()->recipe_id)->toBe($newRecipe->id);
});

test('rejects updating to a recipe from another household', function () {
    [$household] = planningHousehold();
    $recipe = Recipe::factory()->for($household)->create();
    $mealPlan = MealPlan::factory()->for($household)->for($recipe)->create();
    $foreignRecipe = Recipe::factory()->for(Household::factory())->create();

    $this->patchJson("/api/meal-plans/{$mealPlan->id}", [
        'recipe_id' => $foreignRecipe->id,
    ])->assertStatus(404);
});

test('rejects updating a meal plan to a slot already taken by another entry', function () {
    [$household] = planningHousehold();
    $recipeA = Recipe::factory()->for($household)->create();
    $recipeB = Recipe::factory()->for($household)->create();

    MealPlan::factory()->for($household)->for($recipeB)->create([
        'date' => '2026-07-20',
        'meal_type' => MealType::Dinner->value,
    ]);
    $mealPlan = MealPlan::factory()->for($household)->for($recipeA)->create([
        'date' => '2026-07-20',
        'meal_type' => MealType::Lunch->value,
    ]);

    $this->patchJson("/api/meal-plans/{$mealPlan->id}", [
        'recipe_id' => $recipeB->id,
        'meal_type' => MealType::Dinner->value,
    ])->assertStatus(422);
});

test('deletes a meal plan scoped to the household', function () {
    [$household] = planningHousehold();
    $otherHousehold = Household::factory()->create();

    $ownRecipe = Recipe::factory()->for($household)->create();
    $ownPlan = MealPlan::factory()->for($household)->for($ownRecipe)->create();

    $foreignRecipe = Recipe::factory()->for($otherHousehold)->create();
    $foreignPlan = MealPlan::factory()->for($otherHousehold)->for($foreignRecipe)->create();

    $this->deleteJson("/api/meal-plans/{$foreignPlan->id}")->assertNotFound();
    $this->deleteJson("/api/meal-plans/{$ownPlan->id}")->assertNoContent();

    expect(MealPlan::find($ownPlan->id))->toBeNull()
        ->and(MealPlan::find($foreignPlan->id))->not->toBeNull();
});

test('summary aggregates calories and protein per family member against their targets', function () {
    [$household] = planningHousehold();

    $parent = FamilyMember::factory()->for($household)->create([
        'daily_calorie_target' => 2200,
        'daily_protein_target_g' => 120,
    ]);
    $child = FamilyMember::factory()->for($household)->create([
        'daily_calorie_target' => 1600,
        'daily_protein_target_g' => 60,
    ]);

    $recipe = Recipe::factory()->for($household)->create([
        'calories_per_serving' => 600,
        'protein_per_serving_g' => 40,
    ]);

    $mealPlan = MealPlan::factory()->for($household)->for($recipe)->create(['date' => '2026-07-20']);
    $mealPlan->familyMembers()->attach([
        $parent->id => ['portion_multiplier' => 1.0],
        $child->id => ['portion_multiplier' => 0.5],
    ]);

    $response = $this->getJson('/api/meal-plans/summary?start_date=2026-07-20&end_date=2026-07-20');

    $response->assertOk();

    $members = collect($response->json('family_members'));
    $parentData = $members->firstWhere('id', $parent->id);
    $childData = $members->firstWhere('id', $child->id);

    expect($parentData['days']['2026-07-20']['calories'])->toBe(600)
        ->and((float) $parentData['days']['2026-07-20']['protein_g'])->toEqual(40.0)
        ->and($childData['days']['2026-07-20']['calories'])->toBe(300)
        ->and((float) $childData['days']['2026-07-20']['protein_g'])->toEqual(20.0);
});
