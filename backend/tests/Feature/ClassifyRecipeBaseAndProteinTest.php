<?php

use App\Enums\ProteinSource;
use App\Enums\RecipeBase;
use App\Enums\RecipeCategory;
use App\Enums\Season;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function classification(array $overrides = []): array
{
    return array_merge([
        'category' => RecipeCategory::MainCourse,
        'seasons' => [Season::Autumn, Season::Winter],
        'needs_side' => false,
        'side_suggestion' => null,
        'base' => RecipeBase::Pasta,
        'protein_source' => ProteinSource::Rind,
    ], $overrides);
}

function recipeWithIngredient(Household $household, string $title, string $ingredient, array $attributes = []): Recipe
{
    $recipe = Recipe::factory()->for($household)->create(array_merge(['title' => $title], $attributes));
    $recipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => $ingredient])->id, ['quantity' => 100, 'unit' => 'g']);

    return $recipe;
}

test('classifies recipes missing category, seasons, base or protein_source and leaves fully-classified ones alone', function () {
    $household = Household::factory()->create();

    $unclassified = recipeWithIngredient($household, 'Spaghetti Bolognese', 'Rinderhack', [
        'category' => null, 'seasons' => null, 'base' => null, 'protein_source' => null,
    ]);

    $alreadyClassified = Recipe::factory()->for($household)->create([
        'title' => 'Gemüsecurry',
        'category' => RecipeCategory::MainCourse->value,
        'seasons' => ['summer'],
        'base' => RecipeBase::Reis->value,
        'protein_source' => ProteinSource::Huelsenfruechte->value,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Spaghetti Bolognese', ['Rinderhack'])
            ->andReturn(classification());
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    expect($unclassified->fresh()->category)->toBe(RecipeCategory::MainCourse)
        ->and($unclassified->fresh()->seasons)->toBe(['autumn', 'winter'])
        ->and($unclassified->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($unclassified->fresh()->protein_source)->toBe(ProteinSource::Rind)
        ->and($alreadyClassified->fresh()->base)->toBe(RecipeBase::Reis)
        ->and($alreadyClassified->fresh()->seasons)->toBe(['summer']);
});

test('fills only the empty fields and never overwrites already-set (possibly hand-corrected) ones', function () {
    $household = Household::factory()->create();

    $recipe = recipeWithIngredient($household, 'Tiramisu', 'Mascarpone', [
        'category' => RecipeCategory::Dessert->value,
        'seasons' => null,
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::MilchprodukteKaese->value,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        // Claude "disagrees" on everything, but only the missing seasons may be written.
        $mock->shouldReceive('classifyBaseAndProtein')->once()->andReturn(classification([
            'category' => RecipeCategory::Snack,
            'seasons' => [Season::Summer],
            'base' => RecipeBase::Reis,
            'protein_source' => ProteinSource::Ei,
        ]));
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    $fresh = $recipe->fresh();
    expect($fresh->seasons)->toBe(['summer'])
        ->and($fresh->category)->toBe(RecipeCategory::Dessert)
        ->and($fresh->base)->toBe(RecipeBase::Sonstiges)
        ->and($fresh->protein_source)->toBe(ProteinSource::MilchprodukteKaese);
});

test('--force re-classifies and overwrites recipes that are already fully classified', function () {
    $household = Household::factory()->create();

    $recipe = recipeWithIngredient($household, 'Linsensuppe', 'Linsen', [
        'category' => RecipeCategory::MainCourse->value,
        'seasons' => ['summer'],
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::KeinHauptprotein->value,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')->once()->andReturn(classification([
            'seasons' => [Season::Autumn, Season::Winter],
        'needs_side' => false,
        'side_suggestion' => null,
            'base' => RecipeBase::Huelsenfruechte,
            'protein_source' => ProteinSource::Huelsenfruechte,
        ]));
    });

    $this->artisan('recipes:classify-base-protein', ['--force' => true])->assertSuccessful();

    expect($recipe->fresh()->base)->toBe(RecipeBase::Huelsenfruechte)
        ->and($recipe->fresh()->seasons)->toBe(['autumn', 'winter']);
});

test('--household limits classification to that household', function () {
    $household = Household::factory()->create();
    $otherHousehold = Household::factory()->create();

    $ownRecipe = recipeWithIngredient($household, 'Own', 'Nudeln', ['category' => null, 'seasons' => null, 'base' => null, 'protein_source' => null]);
    $otherRecipe = recipeWithIngredient($otherHousehold, 'Other', 'Reis', ['category' => null, 'seasons' => null, 'base' => null, 'protein_source' => null]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Own', ['Nudeln'])
            ->andReturn(classification(['protein_source' => ProteinSource::KeinHauptprotein]));
    });

    $this->artisan('recipes:classify-base-protein', ['--household' => $household->id])->assertSuccessful();

    expect($ownRecipe->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($otherRecipe->fresh()->base)->toBeNull();
});

test('fills the side-dish hint as a pair when needs_side is still empty', function () {
    $household = Household::factory()->create();

    $recipe = recipeWithIngredient($household, 'Schweinekoteletts mit Honig', 'Schweinekotelett', [
        'category' => RecipeCategory::MainCourse->value,
        'seasons' => ['autumn'],
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::Schwein->value,
        'needs_side' => null,
        'side_suggestion' => null,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')->once()->andReturn(classification([
            'needs_side' => true,
            'side_suggestion' => 'Kartoffelpüree, Reis oder Rotkohl',
        ]));
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    expect($recipe->fresh()->needs_side)->toBeTrue()
        ->and($recipe->fresh()->side_suggestion)->toBe('Kartoffelpüree, Reis oder Rotkohl');
});

test('never overwrites an already-decided needs_side, and never adds a hint to a "needs no side" recipe', function () {
    $household = Household::factory()->create();

    // Hand-set to "needs no side" (so side_suggestion is legitimately null);
    // seasons is still empty, which is why the recipe gets selected at all.
    $recipe = recipeWithIngredient($household, 'Linseneintopf', 'Linsen', [
        'category' => RecipeCategory::MainCourse->value,
        'seasons' => null,
        'base' => RecipeBase::Huelsenfruechte->value,
        'protein_source' => ProteinSource::Huelsenfruechte->value,
        'needs_side' => false,
        'side_suggestion' => null,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')->once()->andReturn(classification([
            'needs_side' => true,
            'side_suggestion' => 'Brot',
        ]));
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    $fresh = $recipe->fresh();
    expect($fresh->seasons)->toBe(['autumn', 'winter'])
        ->and($fresh->needs_side)->toBeFalse()
        ->and($fresh->side_suggestion)->toBeNull();
});
