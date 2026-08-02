<?php

use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Claude\MealPlanSuggester;
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

test('preview returns sanitized suggestions for empty slots only', function () {
    $household = autoPlanHousehold();
    $recipe = Recipe::factory()->for($household)->create(['title' => 'Pad Thai']);
    $member = FamilyMember::factory()->for($household)->create(['name' => 'Luisa']);

    $this->mock(MealPlanSuggester::class, function ($mock) use ($recipe, $member) {
        $mock->shouldReceive('suggest')->once()->andReturn([
            [
                'date' => '2026-07-20',
                'meal_type' => 'dinner',
                'recipe_id' => $recipe->id,
                'family_members' => [
                    ['family_member_id' => $member->id, 'portion_multiplier' => 1.0],
                    ['family_member_id' => 999999, 'portion_multiplier' => 1.0], // hallucinated
                ],
            ],
            [
                'date' => '2026-07-20',
                'meal_type' => 'lunch',
                'recipe_id' => 999999, // hallucinated recipe
                'family_members' => [],
            ],
        ]);
    });

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner', 'lunch'],
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

test('returns no assignments and never calls the suggester when all slots are already filled', function () {
    $household = autoPlanHousehold();
    $recipe = Recipe::factory()->for($household)->create();

    MealPlan::factory()->for($household)->for($recipe)->create([
        'date' => '2026-07-20',
        'meal_type' => 'dinner',
    ]);

    // No expectation set on the mock — Mockery fails the test if suggest() is called.
    $this->mock(MealPlanSuggester::class);

    $response = $this->postJson('/api/meal-plans/auto-plan?start_date=2026-07-20&end_date=2026-07-20', [
        'meal_types' => ['dinner'],
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
