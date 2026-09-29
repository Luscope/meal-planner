<?php

namespace App\Models;

use App\Enums\MealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'recipe_id',
        'date',
        'meal_type',
        'planned_servings',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'meal_type' => MealType::class,
            'planned_servings' => 'decimal:2',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function familyMembers(): BelongsToMany
    {
        return $this->belongsToMany(FamilyMember::class, 'meal_plan_member')
            ->withPivot('portion_multiplier')
            ->withTimestamps();
    }
}
