<?php

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('updates scalar recipe fields', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['title' => 'Alt']);

    $response = $this->patchJson("/api/recipes/{$recipe->id}", [
        'title' => 'Neuer Titel',
        'servings' => 6,
        'calories_per_serving' => 555,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.title', 'Neuer Titel')
        ->assertJsonPath('data.servings', 6)
        ->assertJsonPath('data.calories_per_serving', 555);

    expect($recipe->fresh()->title)->toBe('Neuer Titel');
});

test('replaces the ingredient list when ingredients are provided', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();
    $oldIngredient = Ingredient::factory()->create(['name' => 'Reis']);
    $recipe->ingredients()->attach($oldIngredient->id, ['quantity' => 200, 'unit' => 'g']);

    $response = $this->patchJson("/api/recipes/{$recipe->id}", [
        'ingredients' => [
            ['name' => 'Tofu', 'quantity' => 300, 'unit' => 'g', 'notes' => 'fest'],
            ['name' => 'Sojasauce', 'quantity' => 2, 'unit' => 'EL'],
        ],
    ]);

    $response->assertOk()->assertJsonCount(2, 'data.ingredients');

    $recipe->refresh()->load('ingredients');

    expect($recipe->ingredients->pluck('name')->sort()->values()->all())->toBe(['Sojasauce', 'Tofu'])
        ->and($recipe->ingredients->firstWhere('name', 'Tofu')->pivot->notes)->toBe('fest');
});

test('leaves ingredients untouched when the field is omitted', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();
    $ingredient = Ingredient::factory()->create();
    $recipe->ingredients()->attach($ingredient->id, ['quantity' => 100, 'unit' => 'g']);

    $this->patchJson("/api/recipes/{$recipe->id}", ['title' => 'Nur Titel geändert'])
        ->assertOk();

    expect($recipe->fresh()->ingredients)->toHaveCount(1);
});

test('rejects updating a recipe belonging to another household', function () {
    actingAsHouseholdUser();
    $foreignRecipe = Recipe::factory()->for(Household::factory())->create();

    $this->patchJson("/api/recipes/{$foreignRecipe->id}", ['title' => 'Hack'])
        ->assertStatus(404);
});
