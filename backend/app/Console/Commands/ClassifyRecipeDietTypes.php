<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use App\Services\Claude\RecipeExtractor;
use Illuminate\Console\Command;
use Throwable;

class ClassifyRecipeDietTypes extends Command
{
    protected $signature = 'recipes:classify-diet-types {--force : Re-classify recipes that already have a diet_type}';

    protected $description = 'Classify recipes (vegan/vegetarian/pescetarian/omnivore) from their ingredients via Claude';

    public function handle(RecipeExtractor $extractor): int
    {
        $query = Recipe::query()->with('ingredients');

        if (! $this->option('force')) {
            $query->whereNull('diet_type');
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
                $dietType = $extractor->classifyDietType($recipe->title, $ingredientNames);
                $recipe->update(['diet_type' => $dietType]);
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
