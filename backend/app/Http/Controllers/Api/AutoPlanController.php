<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Models\FamilyMember;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Services\Claude\MealPlanConstraintExtractor;
use App\Services\MealPlanning\RecipeScorer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutoPlanController extends Controller
{
    use ResolvesDateRange;

    public function preview(Request $request, MealPlanConstraintExtractor $constraintExtractor): JsonResponse
    {
        $householdId = $request->user()->household_id;

        $validated = $request->validate([
            'meal_types' => ['required', 'array', 'min:1'],
            'meal_types.*' => [Rule::in(array_column(MealType::cases(), 'value'))],
            'criteria' => ['nullable', 'string', 'max:1000'],
        ]);

        [$start, $end] = $this->resolveDateRange($request);

        $recipes = Recipe::where('household_id', $householdId)
            // A recipe with no ingredients can't be shopped for or cooked, and
            // (having no ingredient list to classify from) is also always
            // missing category/base/protein_source — it would otherwise slip
            // past every content-based filter below. Never an auto-plan
            // candidate; still editable/plannable manually.
            ->whereHas('ingredients')
            ->with('ingredients:id,name')
            ->get([
                'id', 'title', 'cuisine', 'category', 'diet_type', 'base', 'protein_source',
                'servings', 'prep_time_minutes', 'cook_time_minutes',
                'calories_per_serving', 'protein_per_serving_g',
            ]);

        if ($recipes->isEmpty()) {
            throw ValidationException::withMessages([
                'recipes' => 'Es sind noch keine Rezepte in der Datenbank, aus denen geplant werden könnte.',
            ]);
        }

        $familyMembers = FamilyMember::where('household_id', $householdId)
            ->get(['id', 'name', 'daily_calorie_target', 'daily_protein_target_g'])
            ->toArray();

        $existingSlots = MealPlan::where('household_id', $householdId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['date', 'meal_type'])
            ->map(fn ($plan) => $plan->date->toDateString().'|'.$plan->meal_type->value)
            ->all();

        $emptySlots = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            foreach ($validated['meal_types'] as $mealType) {
                $key = $date->toDateString().'|'.$mealType;

                if (! in_array($key, $existingSlots, true)) {
                    $emptySlots[] = ['date' => $date->toDateString(), 'meal_type' => $mealType];
                }
            }
        }

        if (empty($emptySlots)) {
            return response()->json(['assignments' => []]);
        }

        $constraints = $constraintExtractor->extract($validated['criteria'] ?? null);

        // Sorted by date so each slot's pick is influenced by the ones already
        // made for earlier days in this same run (weekly variety context) —
        // and so "plan_days" below keeps the chronologically first days.
        usort($emptySlots, fn ($a, $b) => $a['date'] <=> $b['date']);

        if (! empty($constraints['plan_days'])) {
            $allowedDates = collect($emptySlots)->pluck('date')->unique()->take($constraints['plan_days'])->all();
            $emptySlots = array_values(array_filter(
                $emptySlots,
                fn ($slot) => in_array($slot['date'], $allowedDates, true)
            ));
        }

        $history = MealPlan::where('household_id', $householdId)
            ->where('date', '>=', $start->copy()->subWeeks((int) config('mealplanner.cooldown_weeks', 3))->toDateString())
            ->where('date', '<', $start->toDateString())
            ->get(['recipe_id', 'date', 'rating'])
            ->map(fn ($plan) => [
                'recipe_id' => $plan->recipe_id,
                'date' => $plan->date->toDateString(),
                'rating' => $plan->rating?->value,
            ])
            ->all();

        $scorer = new RecipeScorer($history, $constraints);

        $recipeCandidates = $recipes->map(fn (Recipe $recipe) => [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'cuisine' => $recipe->cuisine,
            'category' => $recipe->category?->value,
            'diet_type' => $recipe->diet_type?->value,
            'base' => $recipe->base?->value,
            'protein_source' => $recipe->protein_source?->value,
            'prep_time_minutes' => $recipe->prep_time_minutes,
            'cook_time_minutes' => $recipe->cook_time_minutes,
            'ingredient_names' => $recipe->ingredients->pluck('name')->all(),
        ])->all();

        $repeatDays = max(1, (int) ($constraints['repeat_days'] ?? 1));
        $overrideWeekdays = collect($constraints['day_overrides'] ?? [])->pluck('weekday')->all();

        $assignments = [];

        // Batch-cook cadence applies per meal_type independently (e.g. lunch
        // and dinner each get their own 2-day rhythm), never across types.
        foreach (collect($emptySlots)->groupBy('meal_type') as $mealType => $slotsForType) {
            $dates = $slotsForType->pluck('date')->all();
            $groups = $this->groupDatesByRepeatCadence($dates, $repeatDays, $overrideWeekdays);

            foreach ($groups as $group) {
                // The group's first date is the reference for scoring (cooldown,
                // and any day_override — a day with an override always forms
                // its own single-date group, so this is exact, never approximate).
                $picked = $scorer->pick($recipeCandidates, $group[0], $mealType);

                if ($picked === null) {
                    continue;
                }

                foreach ($group as $date) {
                    $assignments[] = [
                        'date' => $date,
                        'meal_type' => $mealType,
                        'recipe_id' => $picked['id'],
                        'family_members' => collect($familyMembers)->map(fn ($member) => [
                            'family_member_id' => $member['id'],
                            'portion_multiplier' => 1.0,
                        ])->all(),
                    ];
                }
            }
        }

        return response()->json(['assignments' => $this->sanitizeAssignments($assignments, $recipeCandidates, $familyMembers)]);
    }

    /**
     * Groups consecutive dates into batches of $repeatDays that will share
     * one recipe (batch cooking) — except a date whose weekday has a
     * criteria day_override, which always becomes its own single-date group
     * (a pinned wish like "freitags Fisch" must never be diluted by being
     * merged into a neighboring day's batch), and the cadence count then
     * restarts cleanly on the following date.
     *
     * @param  list<string>  $dates  Sorted ascending, one meal_type's dates only.
     * @param  list<string>  $overrideWeekdays  Lowercase English weekday names.
     * @return list<list<string>>
     */
    private function groupDatesByRepeatCadence(array $dates, int $repeatDays, array $overrideWeekdays): array
    {
        $groups = [];
        $current = [];

        foreach ($dates as $date) {
            $weekday = mb_strtolower(Carbon::parse($date)->englishDayOfWeek);

            if (in_array($weekday, $overrideWeekdays, true)) {
                if ($current !== []) {
                    $groups[] = $current;
                    $current = [];
                }

                $groups[] = [$date];

                continue;
            }

            $current[] = $date;

            if (count($current) >= $repeatDays) {
                $groups[] = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    public function apply(Request $request): JsonResponse
    {
        $householdId = $request->user()->household_id;

        $validated = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.date' => ['required', 'date'],
            'assignments.*.meal_type' => ['required', Rule::in(array_column(MealType::cases(), 'value'))],
            'assignments.*.recipe_id' => ['required', 'integer'],
            'assignments.*.family_members' => ['nullable', 'array'],
            'assignments.*.family_members.*.family_member_id' => ['required', 'integer'],
            'assignments.*.family_members.*.portion_multiplier' => ['nullable', 'numeric', 'min:0.1'],
        ]);

        $created = 0;

        foreach ($validated['assignments'] as $assignment) {
            $recipe = Recipe::where('household_id', $householdId)->find($assignment['recipe_id']);

            if (! $recipe) {
                continue;
            }

            $members = collect($assignment['family_members'] ?? []);
            $memberIds = $members->pluck('family_member_id')->unique();

            if ($memberIds->isNotEmpty()) {
                $validCount = FamilyMember::where('household_id', $householdId)->whereIn('id', $memberIds)->count();

                if ($validCount !== $memberIds->count()) {
                    continue;
                }
            }

            try {
                $mealPlan = MealPlan::create([
                    'household_id' => $householdId,
                    'recipe_id' => $recipe->id,
                    'date' => $assignment['date'],
                    'meal_type' => $assignment['meal_type'],
                    'planned_servings' => $recipe->servings,
                ]);
            } catch (QueryException) {
                continue;
            }

            $syncData = [];
            foreach ($members as $item) {
                $syncData[$item['family_member_id']] = [
                    'portion_multiplier' => $item['portion_multiplier'] ?? 1.0,
                ];
            }
            $mealPlan->familyMembers()->sync($syncData);

            $created++;
        }

        return response()->json(['created' => $created]);
    }

    /**
     * Drop any hallucinated recipe/family-member IDs and enrich the response
     * with display names so the frontend preview doesn't need extra lookups.
     *
     * @param  array<int, array<string, mixed>>  $assignments
     * @param  array<int, array<string, mixed>>  $recipes
     * @param  array<int, array<string, mixed>>  $familyMembers
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeAssignments(array $assignments, array $recipes, array $familyMembers): array
    {
        $recipesById = collect($recipes)->keyBy('id');
        $membersById = collect($familyMembers)->keyBy('id');

        return collect($assignments)
            ->filter(fn ($assignment) => $recipesById->has($assignment['recipe_id']))
            ->map(function ($assignment) use ($recipesById, $membersById) {
                $assignment['recipe_title'] = $recipesById[$assignment['recipe_id']]['title'];

                $assignment['family_members'] = collect($assignment['family_members'])
                    ->filter(fn ($member) => $membersById->has($member['family_member_id']))
                    ->map(function ($member) use ($membersById) {
                        $member['name'] = $membersById[$member['family_member_id']]['name'];

                        return $member;
                    })
                    ->values()
                    ->all();

                return $assignment;
            })
            ->values()
            ->all();
    }
}
