<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Enums\RecipeSourceType;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractRecipeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Non-streamed Opus responses with a full recipe schema can take longer
     * than the queue worker's default 60s timeout, especially for link imports
     * with a lot of page content to digest.
     */
    public int $timeout = 180;

    public function __construct(
        public readonly RecipeImport $recipeImport,
    ) {}

    public function handle(RecipeExtractor $extractor): void
    {
        $this->recipeImport->update(['status' => ImportStatus::Processing]);

        try {
            $data = match ($this->recipeImport->source_type) {
                RecipeSourceType::Text => $extractor->extractFromText($this->recipeImport->source_text),
                RecipeSourceType::Link => $extractor->extractFromUrl($this->recipeImport->source_url),
                RecipeSourceType::Photo => $extractor->extractFromImage(
                    base64_encode(Storage::disk('local')->get($this->recipeImport->image_path)),
                    $this->guessMediaType($this->recipeImport->image_path),
                ),
            };

            $recipe = $this->persistRecipe($data);

            $this->recipeImport->update([
                'status' => ImportStatus::Completed,
                'recipe_id' => $recipe->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Recipe import failed', [
                'recipe_import_id' => $this->recipeImport->id,
                'error' => $e->getMessage(),
            ]);

            $this->recipeImport->update([
                'status' => ImportStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function persistRecipe(array $data): Recipe
    {
        $recipe = Recipe::create([
            'household_id' => $this->recipeImport->household_id,
            'created_by_user_id' => $this->recipeImport->created_by_user_id,
            'title' => $data['title'],
            'cuisine' => $data['cuisine'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'],
            'servings' => $data['servings'],
            'prep_time_minutes' => $data['prep_time_minutes'] ?? null,
            'cook_time_minutes' => $data['cook_time_minutes'] ?? null,
            'calories_per_serving' => $data['calories_per_serving'] ?? null,
            'protein_per_serving_g' => $data['protein_per_serving_g'] ?? null,
            'carbs_per_serving_g' => $data['carbs_per_serving_g'] ?? null,
            'fat_per_serving_g' => $data['fat_per_serving_g'] ?? null,
            'source_type' => $this->recipeImport->source_type,
            'source_url' => $this->recipeImport->source_url,
            'image_path' => $this->recipeImport->image_path,
            'raw_import_payload' => $data,
        ]);

        $pivotData = [];
        foreach ($data['ingredients'] as $item) {
            $ingredient = Ingredient::firstOrCreate([
                'name' => ucfirst(mb_strtolower(trim($item['name']))),
            ]);

            $pivotData[$ingredient->id] = [
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'notes' => $item['notes'] ?? null,
            ];
        }

        $recipe->ingredients()->attach($pivotData);

        return $recipe;
    }

    private function guessMediaType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /**
     * Safety net for failures outside handle()'s own try/catch — e.g. a
     * dependency (RecipeExtractor) that throws during container resolution,
     * before handle() ever runs. Without this, the import stays stuck at
     * "processing" forever and the frontend polls indefinitely.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Recipe import failed', [
            'recipe_import_id' => $this->recipeImport->id,
            'error' => $exception->getMessage(),
        ]);

        $this->recipeImport->update([
            'status' => ImportStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
