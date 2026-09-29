<?php

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function shoppingHousehold(): Household
{
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();
    Sanctum::actingAs($user);

    return $household;
}

test('scales and aggregates ingredient quantities across meal plans by planned servings', function () {
    $household = shoppingHousehold();
    $rice = Ingredient::factory()->create(['name' => 'Reis']);

    // Recipe A: 2 servings need 200g rice, planned for 4 servings -> 400g
    $recipeA = Recipe::factory()->for($household)->create(['servings' => 2]);
    $recipeA->ingredients()->attach($rice->id, ['quantity' => 200, 'unit' => 'g', 'notes' => null]);
    MealPlan::factory()->for($household)->for($recipeA)->create([
        'date' => '2026-07-20',
        'planned_servings' => 4,
    ]);

    // Recipe B: 1 serving needs 100g rice, planned for 1 serving -> 100g (same ingredient + unit, should merge)
    $recipeB = Recipe::factory()->for($household)->create(['servings' => 1]);
    $recipeB->ingredients()->attach($rice->id, ['quantity' => 100, 'unit' => 'g', 'notes' => null]);
    MealPlan::factory()->for($household)->for($recipeB)->create([
        'date' => '2026-07-21',
        'planned_servings' => 1,
    ]);

    $response = $this->getJson('/api/shopping-list?start_date=2026-07-20&end_date=2026-07-21');

    $response->assertOk()->assertJsonCount(1, 'items');

    $item = $response->json('items.0');

    expect($item['name'])->toBe('Reis')
        ->and($item['unit'])->toBe('g')
        ->and((float) $item['quantity'])->toEqual(500.0)
        ->and($item['recipes'])->toHaveCount(2);
});

test('keeps the same ingredient in different units as separate line items', function () {
    $household = shoppingHousehold();
    $onion = Ingredient::factory()->create(['name' => 'Zwiebel']);

    $gramsRecipe = Recipe::factory()->for($household)->create(['servings' => 1]);
    $gramsRecipe->ingredients()->attach($onion->id, ['quantity' => 50, 'unit' => 'g', 'notes' => null]);
    MealPlan::factory()->for($household)->for($gramsRecipe)->create([
        'date' => '2026-07-20',
        'planned_servings' => 1,
    ]);

    $pieceRecipe = Recipe::factory()->for($household)->create(['servings' => 1]);
    $pieceRecipe->ingredients()->attach($onion->id, ['quantity' => 2, 'unit' => 'Stück', 'notes' => null]);
    MealPlan::factory()->for($household)->for($pieceRecipe)->create([
        'date' => '2026-07-20',
        'planned_servings' => 1,
    ]);

    $response = $this->getJson('/api/shopping-list?start_date=2026-07-20&end_date=2026-07-20');

    $response->assertOk()->assertJsonCount(2, 'items');
});

test('shopping list only includes meal plans from the requesting household and date range', function () {
    $household = shoppingHousehold();
    $otherHousehold = Household::factory()->create();

    $ingredient = Ingredient::factory()->create();

    $ownRecipe = Recipe::factory()->for($household)->create(['servings' => 1]);
    $ownRecipe->ingredients()->attach($ingredient->id, ['quantity' => 10, 'unit' => 'g', 'notes' => null]);
    MealPlan::factory()->for($household)->for($ownRecipe)->create([
        'date' => '2026-07-20',
        'planned_servings' => 1,
    ]);

    // Outside the requested date range
    MealPlan::factory()->for($household)->for($ownRecipe)->create([
        'date' => '2026-08-01',
        'planned_servings' => 1,
    ]);

    // Belongs to another household entirely
    $foreignRecipe = Recipe::factory()->for($otherHousehold)->create(['servings' => 1]);
    $foreignRecipe->ingredients()->attach($ingredient->id, ['quantity' => 999, 'unit' => 'g', 'notes' => null]);
    MealPlan::factory()->for($otherHousehold)->for($foreignRecipe)->create([
        'date' => '2026-07-20',
        'planned_servings' => 1,
    ]);

    $response = $this->getJson('/api/shopping-list?start_date=2026-07-20&end_date=2026-07-20');

    $response->assertOk()->assertJsonCount(1, 'items');
    expect((float) $response->json('items.0.quantity'))->toEqual(10.0);
});
