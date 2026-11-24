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
        {--force : Re-classify and overwrite values that are already set (default: only fill empty ones)}';

    protected $description = 'Classify recipes (category + seasons + side-dish need + base ingredient + protein source) from their title/ingredients via Claude';

    public function handle(RecipeExtractor $extractor): int
    {
        $query = Recipe::query()->with('ingredients');

        if ($household = $this->option('household')) {
            $query->where('household_id', $household);
        }

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('category')->orWhereNull('seasons')->orWhereNull('needs_side')
                    ->orWhereNull('base')->orWhereNull('protein_source');
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
                $classified = [
                    'category' => $result['category'],
                    'seasons' => array_map(fn ($season) => $season->value, $result['seasons']),
                    'needs_side' => $result['needs_side'],
                    'side_suggestion' => $result['side_suggestion'],
                    'base' => $result['base'],
                    'protein_source' => $result['protein_source'],
                ];

                // Without --force, only fill what is still empty so manual
                // corrections to already-classified fields are never overwritten.
                if (! $this->option('force')) {
                    // needs_side and side_suggestion belong together: a null
                    // side_suggestion is a valid value ("needs no side"), so
                    // judge the pair by needs_side alone.
                    if ($recipe->needs_side !== null) {
                        unset($classified['needs_side'], $classified['side_suggestion']);
                    }

                    $classified = array_filter(
                        $classified,
                        fn ($value, $field) => $field === 'needs_side'
                            || $field === 'side_suggestion'
                            || $recipe->getAttribute($field) === null,
                        ARRAY_FILTER_USE_BOTH
                    );
                }

                $recipe->update($classified);
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
