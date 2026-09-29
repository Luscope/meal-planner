<?php

use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Claude\MealPlanConstraintExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function autoPlanHousehold(): Household
{
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();
    Sanctum::actingAs($user);

    return $household;
}

// A recipe is only an auto-plan candidate once it has at least one
// ingredient (see AutoPlanController::preview()'s whereHas('ingredients')),
// so every test recipe that should actually be suggested needs one attached.
function withIngredient(Recipe $recipe, string $name = 'Testzutat'): Recipe
{
    $ingredient = Ingredient::firstOrCreate(['name' => $name]);
    $recipe->ingredients()->attach($ingredient->id, ['quantity' => 1, 'unit' => 'Stück']);

    return $recipe;
}

function noConstraints(): array
{
    return [
        'cuisines_prefer' => [],
        'cuisines_avoid' => [],
        'exclude_ingredients' => [],
        'max_prep_minutes' => null,
        'dietary_requirement' => null,
        'day_overrides' => [],
        'plan_days' => null,
        'repeat_days' => null,
        'notes' => null,
    ];
}

test('preview returns assignments for empty slots, enriched with recipe/member names', function () {
    $household = autoPlanHousehold();
    $recipe = withIngredient(Recipe::factory()->for($household)->create(['title' => 'Pad Thai', 'cuisine' => 'thailändisch']));
    $member = FamilyMember::factory()->for($household)->create(['name' => 'Luisa']);

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn(noConstraints());
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner'],
        'criteria' => 'wenig Fleisch',
    ]);

    $response->assertOk();

    $assignments = $response->json('assignments');

    expect($assignments)->toHaveCount(1)
        ->and($assignments[0]['recipe_id'])->toBe($recipe->id)
        ->and($assignments[0]['recipe_title'])->toBe('Pad Thai')
        ->and($assignments[0]['family_members'])->toHaveCount(1)
        ->and($assignments[0]['family_members'][0]['name'])->toBe('Luisa');
});

test('rejects preview when the household has no recipes', function () {
    autoPlanHousehold();

    $this->postJson('/api/meal-plans/auto-plan', [
        'meal_types' => ['dinner'],
    ])->assertStatus(422);
});

test('rejects preview when every recipe has no ingredients', function () {
    $household = autoPlanHousehold();
    Recipe::factory()->for($household)->create();

    $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner'],
    ])->assertStatus(422);
});

test('returns no assignments and never calls the constraint extractor when all slots are already filled', function () {
    $household = autoPlanHousehold();
    $recipe = withIngredient(Recipe::factory()->for($household)->create());

    MealPlan::factory()->for($household)->for($recipe)->create([
        'date' => '2026-07-20',
        'meal_type' => 'dinner',
    ]);

    // No expectation set on the mock — Mockery fails the test if extract() is called.
    $this->mock(MealPlanConstraintExtractor::class);

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner'],
    ]);

    $response->assertOk()->assertJson(['assignments' => []]);
});

test('preview skips a slot entirely when every recipe is filtered out by a hard constraint', function () {
    $household = autoPlanHousehold();
    withIngredient(Recipe::factory()->for($household)->create(['diet_type' => 'omnivore']));
    FamilyMember::factory()->for($household)->create();

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn([
            ...noConstraints(),
            'dietary_requirement' => App\Enums\DietType::Vegan,
        ]);
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner'],
        'criteria' => 'vegan bitte',
    ]);

    $response->assertOk()->assertJson(['assignments' => []]);
});

test('preview never suggests a dessert for a breakfast slot, even when it is the only recipe', function () {
    $household = autoPlanHousehold();
    withIngredient(Recipe::factory()->for($household)->create(['category' => 'dessert']));
    FamilyMember::factory()->for($household)->create();

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn(noConstraints());
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['breakfast'],
    ]);

    $response->assertOk()->assertJson(['assignments' => []]);
});

test('plan_days limits the auto-plan to only the chronologically first N days of the range', function () {
    $household = autoPlanHousehold();
    withIngredient(Recipe::factory()->for($household)->create());
    FamilyMember::factory()->for($household)->create();

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn([
            ...noConstraints(),
            'plan_days' => 2,
        ]);
    });

    // A 5-day range, but plan_days should cap it to just the first two days.
    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-24', [
        'meal_types' => ['dinner'],
        'criteria' => 'nur für zwei Tage',
    ]);

    $response->assertOk();

    $dates = collect($response->json('assignments'))->pluck('date')->all();

    expect($dates)->toBe(['2026-07-20', '2026-07-21']);
});

test('repeat_days batches consecutive days of the same meal_type to share one recipe', function () {
    $household = autoPlanHousehold();
    withIngredient(Recipe::factory()->for($household)->create(['title' => 'Rezept A']));
    withIngredient(Recipe::factory()->for($household)->create(['title' => 'Rezept B']));
    FamilyMember::factory()->for($household)->create();

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn([
            ...noConstraints(),
            'repeat_days' => 2,
        ]);
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-23', [
        'meal_types' => ['dinner'],
        'criteria' => 'Gerichte für zwei Tage einplanen',
    ]);

    $response->assertOk();

    $assignments = collect($response->json('assignments'))->keyBy('date');

    expect($assignments)->toHaveCount(4)
        ->and($assignments['2026-07-20']['recipe_id'])->toBe($assignments['2026-07-21']['recipe_id'])
        ->and($assignments['2026-07-22']['recipe_id'])->toBe($assignments['2026-07-23']['recipe_id']);
});

test('a day_override forces its own single-day group, breaking the repeat_days batch there', function () {
    config(['mealplanner.candidate_pool_size' => 1, 'mealplanner.weights.random_jitter' => 0]);

    $household = autoPlanHousehold();
    // A global cuisine preference for chicken's cuisine makes the non-override
    // days deterministic (chicken always wins the Monday/Tuesday tie), so only
    // Wednesday's much larger day_override protein bonus can flip the pick to fish.
    $chicken = withIngredient(Recipe::factory()->for($household)->create(['protein_source' => 'huhn_gefluegel', 'cuisine' => 'deutsch']), 'Huhn');
    $fish = withIngredient(Recipe::factory()->for($household)->create(['protein_source' => 'fisch_meeresfruechte', 'cuisine' => 'italienisch']), 'Fisch');
    FamilyMember::factory()->for($household)->create();

    // 2026-07-20 is a Monday; compute Wednesday's weekday name rather than
    // hardcoding it, so the test stays correct regardless of the calendar.
    $wednesday = '2026-07-22';
    $weekday = mb_strtolower(\Illuminate\Support\Carbon::parse($wednesday)->englishDayOfWeek);

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) use ($weekday) {
        $mock->shouldReceive('extract')->once()->andReturn([
            ...noConstraints(),
            'cuisines_prefer' => ['deutsch'],
            'repeat_days' => 2,
            'day_overrides' => [
                ['weekday' => $weekday, 'cuisines_prefer' => [], 'protein_source_prefer' => ['fisch_meeresfruechte'], 'max_prep_minutes' => null],
            ],
        ]);
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-23', [
        'meal_types' => ['dinner'],
        'criteria' => 'am liebsten deutsch, mittwochs Fisch, Gerichte für zwei Tage einplanen',
    ]);

    $response->assertOk();

    $assignments = collect($response->json('assignments'))->keyBy('date');

    expect($assignments)->toHaveCount(4)
        // Monday+Tuesday form one batch, sharing the (cuisine-preferred) chicken recipe.
        ->and($assignments['2026-07-20']['recipe_id'])->toBe($chicken->id)
        ->and($assignments['2026-07-21']['recipe_id'])->toBe($chicken->id)
        // Wednesday breaks out on its own and gets the fish recipe specifically.
        ->and($assignments[$wednesday]['recipe_id'])->toBe($fish->id)
        // Thursday starts a fresh single-day group of its own (cadence restarts
        // after the override) and reverts to the cuisine-preferred chicken.
        ->and($assignments['2026-07-23']['recipe_id'])->toBe($chicken->id);
});

test('apply creates meal plans for valid assignments and skips foreign-household IDs', function () {
    $household = autoPlanHousehold();
    $ownRecipe = Recipe::factory()->for($household)->create(['servings' => 3]);
    $member = FamilyMember::factory()->for($household)->create();

    $otherHousehold = Household::factory()->create();
    $foreignRecipe = Recipe::factory()->for($otherHousehold)->create();

    $response = $this->postJson('/api/meal-plans/auto-plan/apply', [
        'assignments' => [
            [
                'date' => '2026-07-20',
                'meal_type' => 'dinner',
                'recipe_id' => $ownRecipe->id,
                'family_members' => [
                    ['family_member_id' => $member->id, 'portion_multiplier' => 1.0],
                ],
            ],
            [
                'date' => '2026-07-21',
                'meal_type' => 'dinner',
                'recipe_id' => $foreignRecipe->id,
                'family_members' => [],
            ],
        ],
    ]);

    $response->assertOk()->assertJson(['created' => 1]);

    expect(MealPlan::where('household_id', $household->id)->count())->toBe(1)
        ->and(MealPlan::where('recipe_id', $foreignRecipe->id)->exists())->toBeFalse();

    $created = MealPlan::first();
    expect((float) $created->planned_servings)->toEqual(3.0)
        ->and($created->familyMembers)->toHaveCount(1);
});
