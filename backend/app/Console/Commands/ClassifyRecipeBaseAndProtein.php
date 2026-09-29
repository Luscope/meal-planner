<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Console\Command;
use Throwable;

class ClassifyRecipeBaseAndProtein extends Command
{
    protected $signature = 'recipes:classify-base-protein
        {--household= : Only classify recipes belonging to this household ID}
        {--force : Re-classify recipes that already have a category/base/protein_source}';

    protected $description = 'Classify recipes (category + base ingredient + protein source) from their title/ingredients via Claude';

    public function handle(RecipeExtractor $extractor): int
    {
        $query = Recipe::query()->with('ingredients');

        if ($household = $this->option('household')) {
            $query->where('household_id', $household);
        }

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('category')->orWhereNull('base')->orWhereNull('protein_source');
            });
        }

        $recipes = $query->get();

        if ($recipes->isEmpty()) {
            $this->info('Nothing to classify.');

            return self::SUCCESS;
        }

        $this->info("Classifying {$recipes->count()} recipe(s)…");
        $bar = $this->output->createProgressBar($recipes->count());
        $bar->start();

        $failures = 0;

        foreach ($recipes as $recipe) {
            $ingredientNames = $recipe->ingredients->pluck('name')->all();

            if ($ingredientNames === []) {
                $bar->advance();

                continue;
            }

            try {
                $result = $extractor->classifyBaseAndProtein($recipe->title, $ingredientNames);
                $recipe->update([
                    'category' => $result['category'],
                    'base' => $result['base'],
                    'protein_source' => $result['protein_source'],
                ]);
            } catch (Throwable $e) {
                $failures++;
                $this->newLine();
                $this->warn("Failed for recipe #{$recipe->id} \"{$recipe->title}\": {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($failures > 0) {
            $this->warn("Done, with {$failures} failure(s) — re-run the command to retry those.");
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }
}
