<?php

namespace App\Services\MealPlanning;

use App\Enums\DietType;
use Illuminate\Support\Carbon;

/**
 * Deterministically picks a recipe for one meal-plan slot from a household's
 * own recipes — no LLM involved, so it has no tendency to drift toward
 * statistically "typical" choices the way a free-form Claude prompt does.
 *
 * Usage: construct once per auto-plan run with the household's recent
 * `meal_plans` history, then call {@see pick()} once per empty slot in
 * date order. Each pick updates the scorer's internal weekly context
 * (cuisine/base/protein counters + a synthetic history entry), so later
 * slots in the same run are influenced by earlier picks.
 *
 * Recipes are plain arrays (not Eloquent models) so this class — and its
 * tests — never need a database: {id, cuisine, diet_type, base,
 * protein_source, prep_time_minutes, cook_time_minutes, ingredient_names}.
 */
class RecipeScorer
{
    /** @var array<string, int> */
    private array $cuisineCounts = [];

    /** @var array<string, int> */
    private array $baseCounts = [];

    /** @var array<string, int> */
    private array $proteinCounts = [];

    /** @var list<array{recipe_id: int, date: string, rating: ?string}> */
    private array $runHistory;

    /**
     * @param  list<array{recipe_id: int, date: string, rating: ?string}>  $history  Recent meal_plans rows for the household.
     * @param  array{cuisines_prefer?: list<string>, cuisines_avoid?: list<string>, exclude_ingredients?: list<string>, max_prep_minutes?: int|null, dietary_requirement?: DietType|string|null, notes?: string|null}  $constraints
     */
    public function __construct(
        array $history = [],
        private readonly array $constraints = [],
    ) {
        $this->runHistory = $history;
    }

    /**
     * Picks one recipe for the given slot date from the given candidates, or
     * null if every candidate fails a hard filter. Updates the internal
     * weekly context before returning.
     *
     * @param  list<array<string, mixed>>  $recipes
     * @return array<string, mixed>|null
     */
    public function pick(array $recipes, string $slotDate): ?array
    {
        $candidates = $this->applyHardFilters($recipes);

        if ($candidates === []) {
            return null;
        }

        $scored = array_map(fn ($recipe) => [
            'recipe' => $recipe,
            'score' => $this->score($recipe, $slotDate),
        ], $candidates);

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $poolSize = (int) config('mealplanner.candidate_pool_size', 6);
        $pool = array_slice($scored, 0, max(1, $poolSize));

        $chosen = $this->pickWeightedRandom($pool)['recipe'];

        $this->recordAssignment($chosen, $slotDate);

        return $chosen;
    }

    /**
     * @param  list<array<string, mixed>>  $recipes
     * @return list<array<string, mixed>>
     */
    private function applyHardFilters(array $recipes): array
    {
        return array_values(array_filter(
            $recipes,
            fn ($recipe) => $this->passesDietaryRequirement($recipe)
                && $this->passesIngredientExclusion($recipe)
                && $this->passesPrepTimeLimit($recipe)
                && $this->passesCuisineCap($recipe)
        ));
    }

    private function passesDietaryRequirement(array $recipe): bool
    {
        $requirement = $this->constraints['dietary_requirement'] ?? null;

        if ($requirement === null) {
            return true;
        }

        $requirement = $requirement instanceof DietType ? $requirement->value : $requirement;

        // Hierarchy: vegan recipes satisfy every stricter-or-equal requirement,
        // down to omnivore recipes which only satisfy an omnivore requirement.
        $allowedDietTypes = match ($requirement) {
            'vegan' => ['vegan'],
            'vegetarian' => ['vegan', 'vegetarian'],
            'pescetarian' => ['vegan', 'vegetarian', 'pescetarian'],
            default => null, // omnivore, or an unrecognized value => no restriction
        };

        if ($allowedDietTypes === null) {
            return true;
        }

        return in_array($recipe['diet_type'] ?? null, $allowedDietTypes, true);
    }

    private function passesIngredientExclusion(array $recipe): bool
    {
        $excluded = $this->constraints['exclude_ingredients'] ?? [];

        if ($excluded === []) {
            return true;
        }

        $names = array_map(fn ($name) => mb_strtolower($name), $recipe['ingredient_names'] ?? []);

        foreach ($excluded as $term) {
            $term = mb_strtolower(trim($term));

            if ($term === '') {
                continue;
            }

            foreach ($names as $name) {
                if (str_contains($name, $term) || str_contains($term, $name)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function passesPrepTimeLimit(array $recipe): bool
    {
        $max = $this->constraints['max_prep_minutes'] ?? null;

        if ($max === null) {
            return true;
        }

        $total = (int) ($recipe['prep_time_minutes'] ?? 0) + (int) ($recipe['cook_time_minutes'] ?? 0);

        return $total <= $max;
    }

    private function passesCuisineCap(array $recipe): bool
    {
        $cuisine = $recipe['cuisine'] ?? null;

        if ($cuisine === null) {
            return true;
        }

        $cap = (int) config('mealplanner.max_per_cuisine_per_week', 2);

        return ($this->cuisineCounts[$cuisine] ?? 0) < $cap;
    }

    private function score(array $recipe, string $slotDate): float
    {
        $weights = config('mealplanner.weights', []);
        $score = 0.0;

        $score += ($weights['cooldown'] ?? 0.0) * $this->cooldownFactor((int) $recipe['id'], $slotDate);

        $lastRating = $this->lastRating((int) $recipe['id']);

        if ($lastRating === 'liked') {
            $score += $weights['liked'] ?? 0.0;
        } elseif ($lastRating === 'disliked') {
            $score += $weights['disliked'] ?? 0.0;
        }

        $cuisine = $recipe['cuisine'] ?? null;

        if ($cuisine !== null) {
            if ($this->matchesAny($cuisine, $this->constraints['cuisines_prefer'] ?? [])) {
                $score += $weights['cuisine_preferred'] ?? 0.0;
            }

            if ($this->matchesAny($cuisine, $this->constraints['cuisines_avoid'] ?? [])) {
                $score += $weights['cuisine_avoided'] ?? 0.0;
            }
        }

        if (! empty($recipe['base'])) {
            $score += ($weights['base_repetition'] ?? 0.0) * ($this->baseCounts[$recipe['base']] ?? 0);
        }

        if (! empty($recipe['protein_source'])) {
            $score += ($weights['protein_repetition'] ?? 0.0) * ($this->proteinCounts[$recipe['protein_source']] ?? 0);
        }

        $jitter = $weights['random_jitter'] ?? 0.0;

        if ($jitter > 0) {
            $score += (mt_rand(-1000, 1000) / 1000) * $jitter;
        }

        return $score;
    }

    /**
     * 0.0 = used this week already, 1.0 = never used (or used cooldown_weeks+ ago).
     */
    private function cooldownFactor(int $recipeId, string $slotDate): float
    {
        $cooldownWeeks = max(1, (int) config('mealplanner.cooldown_weeks', 3));
        $lastUsed = $this->lastUsedDate($recipeId);

        if ($lastUsed === null) {
            return 1.0;
        }

        $weeksSince = Carbon::parse($lastUsed)->diffInDays(Carbon::parse($slotDate), false) / 7;

        return max(0.0, min(1.0, $weeksSince / $cooldownWeeks));
    }

    private function lastUsedDate(int $recipeId): ?string
    {
        $dates = array_column(
            array_filter($this->runHistory, fn ($entry) => $entry['recipe_id'] === $recipeId),
            'date'
        );

        if ($dates === []) {
            return null;
        }

        rsort($dates);

        return $dates[0];
    }

    private function lastRating(int $recipeId): ?string
    {
        $entries = array_values(array_filter(
            $this->runHistory,
            fn ($entry) => $entry['recipe_id'] === $recipeId && ! empty($entry['rating'])
        ));

        if ($entries === []) {
            return null;
        }

        usort($entries, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $entries[0]['rating'];
    }

    /**
     * @param  list<string>  $terms
     */
    private function matchesAny(string $cuisine, array $terms): bool
    {
        $cuisine = mb_strtolower($cuisine);

        foreach ($terms as $term) {
            $term = mb_strtolower(trim($term));

            if ($term !== '' && (str_contains($cuisine, $term) || str_contains($term, $cuisine))) {
                return true;
            }
        }

        return false;
    }

    private function recordAssignment(array $recipe, string $slotDate): void
    {
        $this->runHistory[] = ['recipe_id' => (int) $recipe['id'], 'date' => $slotDate, 'rating' => null];

        if (! empty($recipe['cuisine'])) {
            $this->cuisineCounts[$recipe['cuisine']] = ($this->cuisineCounts[$recipe['cuisine']] ?? 0) + 1;
        }

        if (! empty($recipe['base'])) {
            $this->baseCounts[$recipe['base']] = ($this->baseCounts[$recipe['base']] ?? 0) + 1;
        }

        if (! empty($recipe['protein_source'])) {
            $this->proteinCounts[$recipe['protein_source']] = ($this->proteinCounts[$recipe['protein_source']] ?? 0) + 1;
        }
    }

    /**
     * @param  list<array{recipe: array<string, mixed>, score: float}>  $scored
     * @return array{recipe: array<string, mixed>, score: float}
     */
    private function pickWeightedRandom(array $scored): array
    {
        if (count($scored) === 1) {
            return $scored[0];
        }

        $minScore = min(array_column($scored, 'score'));
        $shift = $minScore < 0 ? -$minScore : 0.0;
        $epsilon = 0.01;

        $weights = array_map(fn ($candidate) => $candidate['score'] + $shift + $epsilon, $scored);
        $total = array_sum($weights);

        $r = (mt_rand() / mt_getrandmax()) * $total;
        $cumulative = 0.0;

        foreach ($scored as $i => $candidate) {
            $cumulative += $weights[$i];

            if ($r <= $cumulative) {
                return $candidate;
            }
        }

        return $scored[array_key_last($scored)];
    }
}
