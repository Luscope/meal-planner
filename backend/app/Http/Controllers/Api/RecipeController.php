<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecipeCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'cuisine' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in(array_column(RecipeCategory::cases(), 'value'))],
            'search' => ['nullable', 'string'],
            'min_calories' => ['nullable', 'integer', 'min:0'],
            'max_calories' => ['nullable', 'integer', 'min:0'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*' => ['string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $recipes = Recipe::query()
            ->where('household_id', $request->user()->household_id)
            ->when($validated['cuisine'] ?? null, fn ($q, $cuisine) => $q->where('cuisine', 'like', "%{$cuisine}%"))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('title', 'like', "%{$search}%"))
            ->when($validated['min_calories'] ?? null, fn ($q, $min) => $q->where('calories_per_serving', '>=', $min))
            ->when($validated['max_calories'] ?? null, fn ($q, $max) => $q->where('calories_per_serving', '<=', $max))
            ->when($validated['ingredients'] ?? null, function ($q, $ingredients) {
                foreach ($ingredients as $ingredientName) {
                    $q->whereHas(
                        'ingredients',
                        fn ($iq) => $iq->where('name', 'like', "%{$ingredientName}%")
                    );
                }
            })
            ->with('ingredients')
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return RecipeResource::collection($recipes);
    }

    public function show(Request $request, Recipe $recipe): RecipeResource
    {
        if ($recipe->household_id !== $request->user()->household_id) {
            abort(404);
        }

        return new RecipeResource($recipe->load('ingredients'));
    }

    public function update(Request $request, Recipe $recipe): RecipeResource
    {
        if ($recipe->household_id !== $request->user()->household_id) {
            abort(404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'cuisine' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_column(RecipeCategory::cases(), 'value'))],
            'description' => ['nullable', 'string'],
            'instructions' => ['sometimes', 'array', 'min:1'],
            'instructions.*' => ['string'],
            'servings' => ['sometimes', 'integer', 'min:1'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'cook_time_minutes' => ['nullable', 'integer', 'min:0'],
            'calories_per_serving' => ['nullable', 'integer', 'min:0'],
            'protein_per_serving_g' => ['nullable', 'numeric', 'min:0'],
            'carbs_per_serving_g' => ['nullable', 'numeric', 'min:0'],
            'fat_per_serving_g' => ['nullable', 'numeric', 'min:0'],
            'ingredients' => ['sometimes', 'array'],
            'ingredients.*.name' => ['required_with:ingredients', 'string', 'max:255'],
            'ingredients.*.quantity' => ['required_with:ingredients', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['required_with:ingredients', 'string', 'max:50'],
            'ingredients.*.notes' => ['nullable', 'string'],
        ]);

        $recipe->update(Arr::except($validated, ['ingredients']));

        if (array_key_exists('ingredients', $validated)) {
            $pivotData = [];

            foreach ($validated['ingredients'] as $item) {
                $ingredient = Ingredient::firstOrCreate([
                    'name' => ucfirst(mb_strtolower(trim($item['name']))),
                ]);

                $pivotData[$ingredient->id] = [
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'notes' => $item['notes'] ?? null,
                ];
            }

            $recipe->ingredients()->sync($pivotData);
        }

        return new RecipeResource($recipe->load('ingredients'));
    }

    public function cuisines(Request $request): JsonResponse
    {
        $cuisines = Recipe::query()
            ->where('household_id', $request->user()->household_id)
            ->whereNotNull('cuisine')
            ->distinct()
            ->orderBy('cuisine')
            ->pluck('cuisine');

        return response()->json($cuisines);
    }
}
