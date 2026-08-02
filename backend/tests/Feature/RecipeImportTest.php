<?php

use App\Models\Household;
use App\Models\RecipeImport;
use App\Models\User;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('importing a recipe from text creates a recipe with ingredients', function () {
    config(['queue.default' => 'sync']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('extractFromText')->once()->andReturn([
            'title' => 'Pad Thai',
            'cuisine' => 'thailändisch',
            'description' => null,
            'servings' => 2,
            'prep_time_minutes' => 15,
            'cook_time_minutes' => 10,
            'calories_per_serving' => 550,
            'protein_per_serving_g' => 20.5,
            'carbs_per_serving_g' => 60.0,
            'fat_per_serving_g' => 18.0,
            'instructions' => ['Nudeln einweichen', 'Alles anbraten', 'Servieren'],
            'ingredients' => [
                ['name' => 'Reisnudeln', 'quantity' => 200, 'unit' => 'g', 'notes' => null],
                ['name' => 'Ei', 'quantity' => 2, 'unit' => 'Stück', 'notes' => null],
            ],
        ]);
    });

    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/recipe-imports', [
        'text' => 'Pad Thai Rezept: 200g Reisnudeln, 2 Eier, ...',
    ]);

    $response->assertStatus(202);

    $recipeImport = RecipeImport::first();

    expect($recipeImport->status->value)->toBe('completed')
        ->and($recipeImport->recipe)->not->toBeNull()
        ->and($recipeImport->recipe->title)->toBe('Pad Thai')
        ->and($recipeImport->recipe->ingredients)->toHaveCount(2);
});

test('rejects a request with no or multiple import sources', function () {
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/recipe-imports', [])->assertStatus(422);

    $this->postJson('/api/recipe-imports', [
        'text' => 'foo',
        'url' => 'https://example.com',
    ])->assertStatus(422);
});

test('rejects import when the user has no household', function () {
    $user = User::factory()->create(['household_id' => null]);

    Sanctum::actingAs($user);

    $this->postJson('/api/recipe-imports', ['text' => 'foo'])->assertStatus(422);
});
