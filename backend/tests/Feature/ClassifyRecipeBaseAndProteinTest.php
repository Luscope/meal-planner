<?php

use App\Enums\ProteinSource;
use App\Enums\RecipeBase;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('classifies recipes missing base or protein_source and leaves classified ones alone', function () {
    $household = Household::factory()->create();

    $unclassified = Recipe::factory()->for($household)->create(['title' => 'Spaghetti Bolognese', 'base' => null, 'protein_source' => null]);
    $ingredient = Ingredient::firstOrCreate(['name' => 'Rinderhack']);
    $unclassified->ingredients()->attach($ingredient->id, ['quantity' => 500, 'unit' => 'g']);

    $alreadyClassified = Recipe::factory()->for($household)->create([
        'title' => 'Gemüsecurry',
        'base' => RecipeBase::Reis->value,
        'protein_source' => ProteinSource::Huelsenfruechte->value,
    ]);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Spaghetti Bolognese', ['Rinderhack'])
            ->andReturn(['base' => RecipeBase::Pasta, 'protein_source' => ProteinSource::Rind]);
    });

    $this->artisan('recipes:classify-base-protein')->assertSuccessful();

    expect($unclassified->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($unclassified->fresh()->protein_source)->toBe(ProteinSource::Rind)
        ->and($alreadyClassified->fresh()->base)->toBe(RecipeBase::Reis)
        ->and($alreadyClassified->fresh()->protein_source)->toBe(ProteinSource::Huelsenfruechte);
});

test('--force re-classifies recipes that already have base and protein_source', function () {
    $household = Household::factory()->create();

    $recipe = Recipe::factory()->for($household)->create([
        'title' => 'Linsensuppe',
        'base' => RecipeBase::Sonstiges->value,
        'protein_source' => ProteinSource::KeinHauptprotein->value,
    ]);
    $ingredient = Ingredient::firstOrCreate(['name' => 'Linsen']);
    $recipe->ingredients()->attach($ingredient->id, ['quantity' => 200, 'unit' => 'g']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->andReturn(['base' => RecipeBase::Huelsenfruechte, 'protein_source' => ProteinSource::Huelsenfruechte]);
    });

    $this->artisan('recipes:classify-base-protein', ['--force' => true])->assertSuccessful();

    expect($recipe->fresh()->base)->toBe(RecipeBase::Huelsenfruechte);
});

test('--household limits classification to that household', function () {
    $household = Household::factory()->create();
    $otherHousehold = Household::factory()->create();

    $ownRecipe = Recipe::factory()->for($household)->create(['title' => 'Own', 'base' => null, 'protein_source' => null]);
    $ownRecipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => 'Nudeln'])->id, ['quantity' => 200, 'unit' => 'g']);

    $otherRecipe = Recipe::factory()->for($otherHousehold)->create(['title' => 'Other', 'base' => null, 'protein_source' => null]);
    $otherRecipe->ingredients()->attach(Ingredient::firstOrCreate(['name' => 'Reis'])->id, ['quantity' => 200, 'unit' => 'g']);

    $this->mock(RecipeExtractor::class, function ($mock) {
        $mock->shouldReceive('classifyBaseAndProtein')
            ->once()
            ->with('Own', ['Nudeln'])
            ->andReturn(['base' => RecipeBase::Pasta, 'protein_source' => ProteinSource::KeinHauptprotein]);
    });

    $this->artisan('recipes:classify-base-protein', ['--household' => $household->id])->assertSuccessful();

    expect($ownRecipe->fresh()->base)->toBe(RecipeBase::Pasta)
        ->and($otherRecipe->fresh()->base)->toBeNull();
});
