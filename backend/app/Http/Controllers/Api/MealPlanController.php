<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Resources\MealPlanResource;
use App\Models\FamilyMember;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MealPlanController extends Controller
{
    use ResolvesDateRange;

    public function index(Request $request): AnonymousResourceCollection
    {
        [$start, $end] = $this->resolveDateRange($request);

        $mealPlans = MealPlan::query()
            ->where('household_id', $request->user()->household_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['recipe', 'familyMembers'])
            ->orderBy('date')
            ->get();

        return MealPlanResource::collection($mealPlans);
    }

    public function store(Request $request): MealPlanResource
    {
        $user = $request->user();
        $householdId = $user->household_id;

        $validated = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'meal_type' => ['required', Rule::in(array_column(MealType::cases(), 'value'))],
            'planned_servings' => ['nullable', 'numeric', 'min:0.5'],
            'family_members' => ['nullable', 'array'],
            'family_members.*.family_member_id' => ['required_with:family_members', 'integer'],
            'family_members.*.portion_multiplier' => ['nullable', 'numeric', 'min:0.1'],
        ]);

        $recipe = Recipe::where('household_id', $householdId)->findOrFail($validated['recipe_id']);

        $this->assertFamilyMembersBelongToHousehold($validated['family_members'] ?? [], $householdId);

        try {
            $mealPlan = MealPlan::create([
                'household_id' => $householdId,
                'recipe_id' => $recipe->id,
                'date' => $validated['date'],
                'meal_type' => $validated['meal_type'],
                'planned_servings' => $validated['planned_servings'] ?? $recipe->servings,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'meal_plan' => 'Dieses Rezept ist für diesen Tag und diese Mahlzeit bereits eingeplant.',
            ]);
        }

        $this->syncFamilyMembers($mealPlan, $validated['family_members'] ?? []);

        return new MealPlanResource($mealPlan->load(['recipe', 'familyMembers']));
    }

    public function update(Request $request, MealPlan $mealPlan): MealPlanResource
    {
        $user = $request->user();

        if ($mealPlan->household_id !== $user->household_id) {
            abort(404);
        }

        $validated = $request->validate([
            'recipe_id' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date'],
            'meal_type' => ['sometimes', Rule::in(array_column(MealType::cases(), 'value'))],
            'planned_servings' => ['sometimes', 'numeric', 'min:0.5'],
            'family_members' => ['sometimes', 'array'],
            'family_members.*.family_member_id' => ['required_with:family_members', 'integer'],
            'family_members.*.portion_multiplier' => ['nullable', 'numeric', 'min:0.1'],
        ]);

        if (array_key_exists('recipe_id', $validated)) {
            Recipe::where('household_id', $user->household_id)->findOrFail($validated['recipe_id']);
        }

        if (array_key_exists('family_members', $validated)) {
            $this->assertFamilyMembersBelongToHousehold($validated['family_members'], $user->household_id);
        }

        try {
            $mealPlan->update(Arr::only($validated, ['recipe_id', 'date', 'meal_type', 'planned_servings']));
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'meal_plan' => 'Dieses Rezept ist für diesen Tag und diese Mahlzeit bereits eingeplant.',
            ]);
        }

        if (array_key_exists('family_members', $validated)) {
            $this->syncFamilyMembers($mealPlan, $validated['family_members']);
        }

        return new MealPlanResource($mealPlan->load(['recipe', 'familyMembers']));
    }

    public function destroy(Request $request, MealPlan $mealPlan): JsonResponse
    {
        if ($mealPlan->household_id !== $request->user()->household_id) {
            abort(404);
        }

        $mealPlan->delete();

        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        [$start, $end] = $this->resolveDateRange($request);

        $householdId = $request->user()->household_id;

        $familyMembers = FamilyMember::where('household_id', $householdId)->get();

        $mealPlans = MealPlan::query()
            ->where('household_id', $householdId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['recipe', 'familyMembers'])
            ->get();

        $members = $familyMembers->map(function (FamilyMember $member) use ($mealPlans) {
            $days = [];

            foreach ($mealPlans as $plan) {
                $assignment = $plan->familyMembers->firstWhere('id', $member->id);

                if ($assignment === null) {
                    continue;
                }

                $multiplier = (float) $assignment->pivot->portion_multiplier;
                $dateKey = $plan->date->toDateString();

                $days[$dateKey] ??= ['calories' => 0, 'protein_g' => 0.0];
                $days[$dateKey]['calories'] += (int) round(($plan->recipe->calories_per_serving ?? 0) * $multiplier);
                $days[$dateKey]['protein_g'] = round(
                    $days[$dateKey]['protein_g'] + ((float) ($plan->recipe->protein_per_serving_g ?? 0)) * $multiplier,
                    2
                );
            }

            return [
                'id' => $member->id,
                'name' => $member->name,
                'daily_calorie_target' => $member->daily_calorie_target,
                'daily_protein_target_g' => $member->daily_protein_target_g,
                'days' => $days,
            ];
        });

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'family_members' => $members,
        ]);
    }

    private function assertFamilyMembersBelongToHousehold(array $familyMembers, int $householdId): void
    {
        $ids = collect($familyMembers)->pluck('family_member_id')->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $validCount = FamilyMember::where('household_id', $householdId)->whereIn('id', $ids)->count();

        if ($validCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'family_members' => 'Ein oder mehrere Familienmitglieder gehören nicht zu deinem Haushalt.',
            ]);
        }
    }

    private function syncFamilyMembers(MealPlan $mealPlan, array $items): void
    {
        $syncData = [];

        foreach ($items as $item) {
            $syncData[$item['family_member_id']] = [
                'portion_multiplier' => $item['portion_multiplier'] ?? 1.0,
            ];
        }

        $mealPlan->familyMembers()->sync($syncData);
    }
}
