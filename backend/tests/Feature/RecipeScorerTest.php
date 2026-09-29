<?php

use App\Enums\DietType;
use App\Services\MealPlanning\RecipeScorer;

// These tests exercise RecipeScorer directly against fixed array fixtures —
// no database, no Claude call. Jitter and the candidate pool are forced to
// their most deterministic settings (pool size 1, no random term) so the
// highest-scoring/only-remaining candidate is always picked.
beforeEach(function () {
    config([
        'mealplanner.candidate_pool_size' => 1,
        'mealplanner.weights.random_jitter' => 0,
    ]);
});

function scorerRecipe(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'title' => 'Test-Rezept',
        'cuisine' => null,
        'category' => null,
        'diet_type' => null,
        'base' => null,
        'protein_source' => null,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 10,
        'ingredient_names' => [],
    ], $overrides);
}

test('cooldown makes a never-used recipe preferred over one used this week', function () {
    $usedRecently = scorerRecipe(['id' => 1]);
    $neverUsed = scorerRecipe(['id' => 2]);

    $history = [
        ['recipe_id' => 1, 'date' => '2026-07-18', 'rating' => null],
    ];

    $scorer = new RecipeScorer($history);

    $picked = $scorer->pick([$usedRecently, $neverUsed], '2026-07-20');

    expect($picked['id'])->toBe(2);
});

test('a weekly cuisine cap excludes further recipes of that cuisine once reached', function () {
    config(['mealplanner.max_per_cuisine_per_week' => 1]);

    $italianA = scorerRecipe(['id' => 1, 'cuisine' => 'italienisch']);
    $italianB = scorerRecipe(['id' => 2, 'cuisine' => 'italienisch']);
    $german = scorerRecipe(['id' => 3, 'cuisine' => 'deutsch']);

    $scorer = new RecipeScorer();
    $recipes = [$italianA, $italianB, $german];

    $first = $scorer->pick($recipes, '2026-07-20');
    expect($first['cuisine'])->toBe('italienisch');

    // The italian cap (1) is now reached — only the german recipe remains.
    $second = $scorer->pick($recipes, '2026-07-21');
    expect($second['id'])->toBe(3);

    // Both cuisines are now at their cap — no candidate survives the hard filter.
    $third = $scorer->pick($recipes, '2026-07-22');
    expect($third)->toBeNull();
});

test('excludes recipes containing an excluded ingredient', function () {
    $withMushrooms = scorerRecipe(['id' => 1, 'ingredient_names' => ['Pilze', 'Sahne']]);
    $withoutMushrooms = scorerRecipe(['id' => 2, 'ingredient_names' => ['Tofu', 'Sojasauce']]);

    $scorer = new RecipeScorer([], ['exclude_ingredients' => ['pilze']]);

    $picked = $scorer->pick([$withMushrooms, $withoutMushrooms], '2026-07-20');

    expect($picked['id'])->toBe(2);
});

test('a strict dietary requirement excludes every recipe that fails it', function () {
    $vegetarian = scorerRecipe(['id' => 1, 'diet_type' => 'vegetarian']);
    $omnivore = scorerRecipe(['id' => 2, 'diet_type' => 'omnivore']);

    $scorer = new RecipeScorer([], ['dietary_requirement' => DietType::Vegan]);

    $picked = $scorer->pick([$vegetarian, $omnivore], '2026-07-20');

    expect($picked)->toBeNull();
});

test('the diet-type hierarchy lets a stricter recipe satisfy a looser requirement', function () {
    $vegan = scorerRecipe(['id' => 1, 'diet_type' => 'vegan']);
    $omnivore = scorerRecipe(['id' => 2, 'diet_type' => 'omnivore']);

    $scorer = new RecipeScorer([], ['dietary_requirement' => DietType::Vegetarian]);

    $picked = $scorer->pick([$vegan, $omnivore], '2026-07-20');

    expect($picked['id'])->toBe(1);
});

test('excludes recipes over the max prep+cook time', function () {
    $slow = scorerRecipe(['id' => 1, 'prep_time_minutes' => 30, 'cook_time_minutes' => 30]);
    $fast = scorerRecipe(['id' => 2, 'prep_time_minutes' => 5, 'cook_time_minutes' => 10]);

    $scorer = new RecipeScorer([], ['max_prep_minutes' => 20]);

    $picked = $scorer->pick([$slow, $fast], '2026-07-20');

    expect($picked['id'])->toBe(2);
});

test('excludes a recipe category not allowed for the given meal_type', function () {
    $dessert = scorerRecipe(['id' => 1, 'category' => 'dessert']);
    $mainCourse = scorerRecipe(['id' => 2, 'category' => 'main_course']);

    $picked = (new RecipeScorer())->pick([$dessert, $mainCourse], '2026-07-20', 'dinner');

    expect($picked['id'])->toBe(2);
});

test('a meal_type category exclusion does not apply to other meal types', function () {
    $dessert = scorerRecipe(['id' => 1, 'category' => 'dessert']);

    $picked = (new RecipeScorer())->pick([$dessert], '2026-07-20', 'snack');

    expect($picked['id'])->toBe(1);
});

test('a day override gives a protein-source bonus only on its matching weekday', function () {
    $fish = scorerRecipe(['id' => 1, 'protein_source' => 'fisch_meeresfruechte']);
    $chicken = scorerRecipe(['id' => 2, 'protein_source' => 'huhn_gefluegel']);

    $thursday = '2026-07-23';
    $friday = '2026-07-24';
    $fridayWeekday = mb_strtolower(\Illuminate\Support\Carbon::parse($friday)->englishDayOfWeek);

    $scorer = new RecipeScorer([], [
        'day_overrides' => [
            ['weekday' => $fridayWeekday, 'cuisines_prefer' => [], 'protein_source_prefer' => ['fisch_meeresfruechte'], 'max_prep_minutes' => null],
        ],
    ]);

    // Thursday: the override doesn't apply — both recipes tie, so the first
    // given (chicken) wins deterministically (pool size 1, no jitter).
    $thursdayPick = $scorer->pick([$chicken, $fish], $thursday);
    expect($thursdayPick['id'])->toBe(2);

    // Friday: the override's protein bonus makes the fish recipe win
    // regardless of array order or the chicken pick's cooldown head start.
    $fridayPick = $scorer->pick([$chicken, $fish], $friday);
    expect($fridayPick['id'])->toBe(1);
});

test('a day override applies a prep-time limit only on its matching weekday', function () {
    $slow = scorerRecipe(['id' => 1, 'prep_time_minutes' => 30, 'cook_time_minutes' => 30]);
    $fast = scorerRecipe(['id' => 2, 'prep_time_minutes' => 5, 'cook_time_minutes' => 10]);

    $monday = '2026-07-20';
    $tuesday = '2026-07-21';
    $mondayWeekday = mb_strtolower(\Illuminate\Support\Carbon::parse($monday)->englishDayOfWeek);

    $scorer = new RecipeScorer([], [
        'day_overrides' => [
            ['weekday' => $mondayWeekday, 'cuisines_prefer' => [], 'protein_source_prefer' => [], 'max_prep_minutes' => 20],
        ],
    ]);

    // Monday: the override's 20-minute cap excludes the slow recipe.
    $mondayPick = $scorer->pick([$slow, $fast], $monday);
    expect($mondayPick['id'])->toBe(2);

    // Tuesday: no override applies (both eligible again), but the fast
    // recipe was just used on Monday, so cooldown now favors the slow one.
    $tuesdayPick = $scorer->pick([$slow, $fast], $tuesday);
    expect($tuesdayPick['id'])->toBe(1);
});
