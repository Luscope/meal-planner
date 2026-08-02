<?php

namespace App\Models;

use App\Enums\RecipeSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'title',
        'cuisine',
        'description',
        'instructions',
        'servings',
        'prep_time_minutes',
        'cook_time_minutes',
        'calories_per_serving',
        'protein_per_serving_g',
        'carbs_per_serving_g',
        'fat_per_serving_g',
        'source_type',
        'source_url',
        'image_path',
        'raw_import_payload',
    ];

    protected function casts(): array
    {
        return [
            'instructions' => 'array',
            'raw_import_payload' => 'array',
            'source_type' => RecipeSourceType::class,
            'protein_per_serving_g' => 'decimal:2',
            'carbs_per_serving_g' => 'decimal:2',
            'fat_per_serving_g' => 'decimal:2',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'recipe_ingredient')
            ->withPivot('quantity', 'unit', 'notes')
            ->withTimestamps();
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }
}
