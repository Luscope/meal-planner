<?php

use App\Enums\ProteinSource;
use App\Enums\RecipeBase;
use App\Enums\RecipeCategory;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('classifies recipes missing category, base or protein_source and leaves fully-classified ones alone', function () {
    $household = Household::factory()->create();

    $unclassified = Recipe::factory()->for($household)->create([
        'title' => 'Spaghetti Bolognese', 'category' => null, 'base' => null, 'protein_source' => null,
    ]);
    $ingredient = Ingredient::firstOrCreate(['name' => 'Rinderhack']);
    $unclassified->ingredients()->attach($ingredient->id, ['quantity' => 500, 'unit' => 'g']);

    $alreadyClassified = Recipe::factory()->for($household)->create([
        'title' => 'Gemüsecurry',
        'category' => RecipeCategory::MainCourse->value,
        'base' => RecipeBase::Reis->value,
        'protein_source' => ProteinSource::Huelsenfruechte->value,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Spaghetti Bolognese', ['Rinderhack'])
            ->andReturn(['category' => RecipeCategory::MainCourse, 'base' => RecipeBase::Pasta, 'protein_source' => ProteinSource::Rind]);
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    expect($unclassified->fresh()->category)->toBe(RecipeCategory::MainCourse)
        ->and($unclassified->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($unclassified->fresh()->protein_source)->toBe(ProteinSource::Rind)
        ->and($alreadyClassified->fresh()->base)->toBe(RecipeBase::Reis)
        ->and($alreadyClassified->fresh()->protein_source)->toBe(ProteinSource::Huelsenfruechte);
});

test('classifies a recipe missing only its category, even when base/protein_source are already set', function () {
    $household = Household::factory()->create();

    $recipe = Recipe::factory()->for($household)->create([
        'title' => 'Tiramisu',
        'category' => null,
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::MilchprodukteKaese->value,
    ]);
    $recipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => 'Mascarpone'])->id, ['quantity' => 250, 'unit' => 'g']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->andReturn(['category' => RecipeCategory::Dessert, 'base' => RecipeBase::Sonstiges, 'protein_source' => ProteinSource::MilchprodukteKaese]);
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    expect($recipe->fresh()->category)->toBe(RecipeCategory::Dessert);
});

test('--force re-classifies recipes that already have category, base and protein_source', function () {
    $household = Household::factory()->create();

    $recipe = Recipe::factory()->for($household)->create([
        'title' => 'Linsensuppe',
        'category' => RecipeCategory::MainCourse->value,
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::KeinHauptprotein->value,
    ]);
    $ingredient = Ingredient::firstOrCreate(['name' => 'Linsen']);
    $recipe->ingredients()->attach($ingredient->id, ['quantity' => 200, 'unit' => 'g']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->andReturn(['category' => RecipeCategory::MainCourse, 'base' => RecipeBase::Huelsenfruechte, 'protein_source' => ProteinSource::Huelsenfruechte]);
    });

    $this->artisan('recipes:classify-base-protein', ['--force' => true])->assertSuccessful();

    expect($recipe->fresh()->base)->toBe(RecipeBase::Huelsenfruechte);
});

test('--household limits classification to that household', function () {
    $household = Household::factory()->create();
    $otherHousehold = Household::factory()->create();

    $ownRecipe = Recipe::factory()->for($household)->create(['title' => 'Own', 'category' => null, 'base' => null, 'protein_source' => null]);
    $ownRecipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => 'Nudeln'])->id, ['quantity' => 200, 'unit' => 'g']);

    $otherRecipe = Recipe::factory()->for($otherHousehold)->create(['title' => 'Other', 'category' => null, 'base' => null, 'protein_source' => null]);
    $otherRecipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => 'Reis'])->id, ['quantity' => 200, 'unit' => 'g']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Own', ['Nudeln'])
            ->andReturn(['category' => RecipeCategory::MainCourse, 'base' => RecipeBase::Pasta, 'protein_source' => ProteinSource::KeinHauptprotein]);
    });

    $this->artisan('recipes:classify-base-protein', ['--household' => $household->id])->assertSuccessful();

    expect($ownRecipe->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($otherRecipe->fresh()->base)->toBeNull();
});
