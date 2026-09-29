<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-plan scoring
    |--------------------------------------------------------------------------
    |
    | Tunables for App\Services\MealPlanning\RecipeScorer, the deterministic
    | scoring service that picks recipes for the auto-generated meal plan.
    | See the class doc block for how each value is used.
    |
    */

    // Hard cap: at most this many meals of the same cuisine per planning week.
    'max_per_cuisine_per_week' => 2,

    // How many weeks of meal_plans history to consider for the cooldown/rating scoring.
    'cooldown_weeks' => 3,

    // How many top-scoring candidates to weighted-randomly pick from per slot.
    'candidate_pool_size' => 6,

    // Soft-scoring weights. Higher magnitude = stronger influence on the score.
    'weights' => [
        // Multiplied by (weeks_since_last_used / cooldown_weeks), so a recipe
        // used this week scores 0 and one unused for cooldown_weeks+ scores full weight.
        'cooldown' => 10.0,

        'liked' => 4.0,
        'disliked' => -6.0,

        'cuisine_preferred' => 5.0,
        'cuisine_avoided' => -8.0,

        // Applied once per matching recipe already used this week for the same base/protein_source.
        'base_repetition' => -3.0,
        'protein_repetition' => -3.0,

        // Small random term (± this value) so ties don't always resolve the same way.
        'random_jitter' => 1.0,
    ],

];
