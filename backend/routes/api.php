<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoPlanController;
use App\Http\Controllers\Api\FamilyMemberController;
use App\Http\Controllers\Api\MealPlanController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RecipeImportController;
use App\Http\Controllers\Api\ShoppingListController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

Route::get('/user', function (Request $request) {
    return $request->user()->load('household');
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/recipe-imports', [RecipeImportController::class, 'store']);
    Route::get('/recipe-imports/{recipeImport}', [RecipeImportController::class, 'show']);

    Route::get('/recipes/cuisines', [RecipeController::class, 'cuisines']);
    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show']);
    Route::patch('/recipes/{recipe}', [RecipeController::class, 'update']);

    Route::get('/meal-plans/summary', [MealPlanController::class, 'summary']);
    Route::get('/meal-plans', [MealPlanController::class, 'index']);
    Route::post('/meal-plans', [MealPlanController::class, 'store']);
    Route::patch('/meal-plans/{mealPlan}', [MealPlanController::class, 'update']);
    Route::delete('/meal-plans/{mealPlan}', [MealPlanController::class, 'destroy']);

    Route::post('/meal-plans/auto-plan', [AutoPlanController::class, 'preview']);
    Route::post('/meal-plans/auto-plan/apply', [AutoPlanController::class, 'apply']);

    Route::get('/shopping-list', [ShoppingListController::class, 'index']);

    Route::get('/family-members', [FamilyMemberController::class, 'index']);
});
