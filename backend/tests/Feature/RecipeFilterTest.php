<?php

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actingAsHouseholdUser(): array
{
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();
    Sanctum::actingAs($user);

    return [$household, $user];
}

test('filters recipes by cuisine', function () {
    [$household] = actingAsHouseholdUser();

    Recipe::factory()->for($household)->create(['cuisine' => 'vietnamesisch']);
    Recipe::factory()->for($household)->create(['cuisine' => 'japanisch']);

    $response = $this->getJson('/api/recipes?cuisine=vietnam');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cuisine', 'vietnamesisch');
});

test('filters recipes by calorie range', function () {
    [$household] = actingAsHouseholdUser();

    Recipe::factory()->for($household)->create(['calories_per_serving' => 300]);
    Recipe::factory()->for($household)->create(['calories_per_serving' => 900]);

    $response = $this->getJson('/api/recipes?max_calories=500');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.calories_per_serving', 300);
});

test('filters recipes requiring all given ingredients', function () {
    [$household] = actingAsHouseholdUser();

    $chicken = Ingredient::factory()->create(['name' => 'Huhn']);
    $rice = Ingredient::factory()->create(['name' => 'Reis']);
    $tofu = Ingredient::factory()->create(['name' => 'Tofu']);

    $chickenRice = Recipe::factory()->for($household)->create(['title' => 'Hähnchen mit Reis']);
    $chickenRice->ingredients()->attach([
        $chicken->id => ['quantity' => 300, 'unit' => 'g', 'notes' => null],
        $rice->id => ['quantity' => 200, 'unit' => 'g', 'notes' => null],
    ]);

    $tofuRice = Recipe::factory()->for($household)->create(['title' => 'Tofu mit Reis']);
    $tofuRice->ingredients()->attach([
        $tofu->id => ['quantity' => 250, 'unit' => 'g', 'notes' => null],
        $rice->id => ['quantity' => 200, 'unit' => 'g', 'notes' => null],
    ]);

    $response = $this->getJson('/api/recipes?'.http_build_query(['ingredients' => ['Huhn', 'Reis']]));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Hähnchen mit Reis');
});

test('recipes are scoped to the requesting household', function () {
    [$household] = actingAsHouseholdUser();
    $otherHousehold = Household::factory()->create();

    Recipe::factory()->for($household)->create(['title' => 'Eigenes Rezept']);
    Recipe::factory()->for($otherHousehold)->create(['title' => 'Fremdes Rezept']);

    $response = $this->getJson('/api/recipes');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Eigenes Rezept');
});

test('show returns 404 for a recipe belonging to another household', function () {
    actingAsHouseholdUser();
    $otherHousehold = Household::factory()->create();
    $foreignRecipe = Recipe::factory()->for($otherHousehold)->create();

    $this->getJson("/api/recipes/{$foreignRecipe->id}")->assertNotFound();
});

test('cuisines endpoint returns distinct cuisines for the household', function () {
    [$household] = actingAsHouseholdUser();

    Recipe::factory()->for($household)->create(['cuisine' => 'thailändisch']);
    Recipe::factory()->for($household)->create(['cuisine' => 'thailändisch']);
    Recipe::factory()->for($household)->create(['cuisine' => 'deutsch']);

    $response = $this->getJson('/api/recipes/cuisines');

    $response->assertOk()
        ->assertJson(['deutsch', 'thailändisch']);
});
