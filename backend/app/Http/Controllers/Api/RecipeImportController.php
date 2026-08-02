<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecipeSourceType;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractRecipeJob;
use App\Models\RecipeImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RecipeImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['nullable', 'url'],
            'text' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $sourcesProvided = collect([$data['url'] ?? null, $data['text'] ?? null, $request->file('image')])
            ->filter()
            ->count();

        if ($sourcesProvided !== 1) {
            throw ValidationException::withMessages([
                'source' => 'Bitte genau eine Importquelle angeben: url, text oder image.',
            ]);
        }

        $user = $request->user();

        if ($user->household_id === null) {
            throw ValidationException::withMessages([
                'household' => 'Dein Account ist noch keinem Haushalt zugeordnet.',
            ]);
        }

        $attributes = [
            'household_id' => $user->household_id,
            'created_by_user_id' => $user->id,
        ];

        if ($request->filled('url')) {
            $attributes['source_type'] = RecipeSourceType::Link;
            $attributes['source_url'] = $data['url'];
        } elseif ($request->filled('text')) {
            $attributes['source_type'] = RecipeSourceType::Text;
            $attributes['source_text'] = $data['text'];
        } else {
            $path = $request->file('image')->store('recipe-imports', 'local');
            $attributes['source_type'] = RecipeSourceType::Photo;
            $attributes['image_path'] = $path;
        }

        $recipeImport = RecipeImport::create($attributes);

        ExtractRecipeJob::dispatch($recipeImport);

        return response()->json([
            'id' => $recipeImport->id,
            'status' => $recipeImport->status,
        ], 202);
    }

    public function show(Request $request, RecipeImport $recipeImport): JsonResponse
    {
        if ($recipeImport->household_id !== $request->user()->household_id) {
            abort(404);
        }

        return response()->json([
            'id' => $recipeImport->id,
            'status' => $recipeImport->status,
            'recipe_id' => $recipeImport->recipe_id,
            'error_message' => $recipeImport->error_message,
        ]);
    }
}
