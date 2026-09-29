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
            ->with('ingredients:id,name')
            ->get([
                'id', 'title', 'cuisine', 'diet_type', 'base', 'protein_source',
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
            'diet_type' => $recipe->diet_type?->value,
            'base' => $recipe->base?->value,
            'protein_source' => $recipe->protein_source?->value,
            'prep_time_minutes' => $recipe->prep_time_minutes,
            'cook_time_minutes' => $recipe->cook_time_minutes,
            'ingredient_names' => $recipe->ingredients->pluck('name')->all(),
        ])->all();

        // Sorted by date so each slot's pick is influenced by the ones already
        // made for earlier days in this same run (weekly variety context).
        usort($emptySlots, fn ($a, $b) => $a['date'] <=> $b['date']);

        $assignments = [];

        foreach ($emptySlots as $slot) {
            $picked = $scorer->pick($recipeCandidates, $slot['date']);

            if ($picked === null) {
                continue;
            }

            $assignments[] = [
                'date' => $slot['date'],
                'meal_type' => $slot['meal_type'],
                'recipe_id' => $picked['id'],
                'family_members' => collect($familyMembers)->map(fn ($member) => [
                    'family_member_id' => $member['id'],
                    'portion_multiplier' => 1.0,
                ])->all(),
            ];
        }

        return response()->json(['assignments' => $this->sanitizeAssignments($assignments, $recipeCandidates, $familyMembers)]);
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
