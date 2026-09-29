<?php

use App\Services\Claude\RecipeExtractor;

function assertPlausibleRecipe(array $data): void
{
    $extractor = new RecipeExtractor();
    $method = new ReflectionMethod(RecipeExtractor::class, 'assertPlausibleRecipe');
    $method->invoke($extractor, $data);
}

test('accepts a recipe where only one ingredient has no measured quantity', function () {
    // "Wasser" with no amount is completely normal in a real recipe.
    assertPlausibleRecipe([
        'ingredients' => [
            ['name' => 'Wurst', 'quantity' => 800, 'unit' => 'g'],
            ['name' => 'Zwiebeln', 'quantity' => 2, 'unit' => 'Stück'],
            ['name' => 'Wasser', 'quantity' => 0, 'unit' => 'ml'],
        ],
    ]);
})->throwsNoExceptions();

test('rejects a placeholder response where every ingredient has quantity zero', function () {
    assertPlausibleRecipe([
        'ingredients' => [
            ['name' => 'unavailable', 'quantity' => 0, 'unit' => 'Stück'],
        ],
    ]);
})->throws(RuntimeException::class, 'Claude konnte kein plausibles Rezept aus der Quelle extrahieren.');
