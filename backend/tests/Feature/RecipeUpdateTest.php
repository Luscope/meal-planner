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

test('updates the recipe category', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['category' => 'main_course']);

    $response = $this->patchJson("/api/recipes/{$recipe->id}", ['category' => 'dessert']);

    $response->assertOk()->assertJsonPath('data.category', 'dessert');
    expect($recipe->fresh()->category)->toBe(App\Enums\RecipeCategory::Dessert);
});

test('rejects an invalid category value on update', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();

    $this->patchJson("/api/recipes/{$recipe->id}", ['category' => 'not-a-real-category'])
        ->assertStatus(422);
});

test('updates the recipe diet type', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['diet_type' => 'omnivore']);

    $response = $this->patchJson("/api/recipes/{$recipe->id}", ['diet_type' => 'vegan']);

    $response->assertOk()->assertJsonPath('data.diet_type', 'vegan');
    expect($recipe->fresh()->diet_type)->toBe(App\Enums\DietType::Vegan);
});

test('updates the recipe seasons', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['seasons' => null]);

    $this->patchJson("/api/recipes/{$recipe->id}", ['seasons' => ['autumn', 'winter']])
        ->assertOk()
        ->assertJsonPath('data.seasons', ['autumn', 'winter']);

    expect($recipe->fresh()->seasons)->toBe(['autumn', 'winter']);
});

test('an empty seasons selection is stored as not classified', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['seasons' => ['summer']]);

    $this->patchJson("/api/recipes/{$recipe->id}", ['seasons' => []])
        ->assertOk()
        ->assertJsonPath('data.seasons', null);

    expect($recipe->fresh()->seasons)->toBeNull();
});

test('rejects an invalid or duplicated season on update', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();

    $this->patchJson("/api/recipes/{$recipe->id}", ['seasons' => ['summer', 'monsoon']])->assertStatus(422);
    $this->patchJson("/api/recipes/{$recipe->id}", ['seasons' => ['summer', 'summer']])->assertStatus(422);
});

test('updates the side-dish hint', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['needs_side' => null, 'side_suggestion' => null]);

    $this->patchJson("/api/recipes/{$recipe->id}", ['needs_side' => true, 'side_suggestion' => 'Reis oder Kartoffeln'])
        ->assertOk()
        ->assertJsonPath('data.needs_side', true)
        ->assertJsonPath('data.side_suggestion', 'Reis oder Kartoffeln');

    expect($recipe->fresh()->needs_side)->toBeTrue()
        ->and($recipe->fresh()->side_suggestion)->toBe('Reis oder Kartoffeln');
});

test('clears the side suggestion when a recipe no longer needs a side', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create(['needs_side' => true, 'side_suggestion' => 'Reis']);

    // Even if a stale suggestion is sent along, "doesn't need a side" wins.
    $this->patchJson("/api/recipes/{$recipe->id}", ['needs_side' => false, 'side_suggestion' => 'Reis'])
        ->assertOk()
        ->assertJsonPath('data.needs_side', false)
        ->assertJsonPath('data.side_suggestion', null);

    $this->patchJson("/api/recipes/{$recipe->id}", ['needs_side' => null])
        ->assertOk()
        ->assertJsonPath('data.needs_side', null);
});

test('rejects an invalid side-dish hint on update', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();

    $this->patchJson("/api/recipes/{$recipe->id}", ['needs_side' => 'maybe'])->assertStatus(422);
    $this->patchJson("/api/recipes/{$recipe->id}", ['needs_side' => true, 'side_suggestion' => str_repeat('x', 256)])->assertStatus(422);
});

test('rejects an invalid diet_type value on update', function () {
    [$household] = actingAsHouseholdUser();
    $recipe = Recipe::factory()->for($household)->create();

    $this->patchJson("/api/recipes/{$recipe->id}", ['diet_type' => 'not-a-real-diet'])
        ->assertStatus(422);
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
