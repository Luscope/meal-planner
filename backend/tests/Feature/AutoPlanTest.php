<?php

use App\Models\FamilyMember;
use App\Models\Household;
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

function noConstraints(): array
{
    return [
        'cuisines_prefer' => [],
        'cuisines_avoid' => [],
        'exclude_ingredients' => [],
        'max_prep_minutes' => null,
        'dietary_requirement' => null,
        'day_overrides' => [],
        'notes' => null,
    ];
}

test('preview returns assignments for empty slots, enriched with recipe/member names', function () {
    $household = autoPlanHousehold();
    $recipe = Recipe::factory()->for($household)->create(['title' => 'Pad Thai', 'cuisine' => 'thailändisch']);
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

test('returns no assignments and never calls the constraint extractor when all slots are already filled', function () {
    $household = autoPlanHousehold();
    $recipe = Recipe::factory()->for($household)->create();

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
    Recipe::factory()->for($household)->create(['diet_type' => 'omnivore']);
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
    Recipe::factory()->for($household)->create(['category' => 'dessert']);
    FamilyMember::factory()->for($household)->create();

    $this->mock(MealPlanConstraintExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->once()->andReturn(noConstraints());
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['breakfast'],
    ]);

    $response->assertOk()->assertJson(['assignments' => []]);
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
