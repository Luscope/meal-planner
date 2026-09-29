<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShoppingListController extends Controller
{
    use ResolvesDateRange;

    public function index(Request $request): JsonResponse
    {
        [$start, $end] = $this->resolveDateRange($request);

        $mealPlans = MealPlan::query()
            ->where('household_id', $request->user()->household_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('recipe.ingredients')
            ->get();

        $items = [];

        foreach ($mealPlans as $plan) {
            $recipe = $plan->recipe;

            if ($recipe === null || (float) $recipe->servings <= 0) {
                continue;
            }

            $scale = (float) $plan->planned_servings / (float) $recipe->servings;

            foreach ($recipe->ingredients as $ingredient) {
                $key = $ingredient->id.'|'.$ingredient->pivot->unit;

                $items[$key] ??= [
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->pivot->unit,
                    'quantity' => 0.0,
                    'recipes' => [],
                ];

                $items[$key]['quantity'] += (float) $ingredient->pivot->quantity * $scale;
                $items[$key]['recipes'][$recipe->id] = $recipe->title;
            }
        }

        $result = collect($items)
            ->map(function (array $item) {
                $item['quantity'] = round($item['quantity'], 2);
                $item['recipes'] = array_values($item['recipes']);

                return $item;
            })
            ->sortBy('name')
            ->values();

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'items' => $result,
        ]);
    }
}
