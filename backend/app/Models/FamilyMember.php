<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FamilyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'user_id',
        'name',
        'daily_calorie_target',
        'daily_protein_target_g',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mealPlans(): BelongsToMany
    {
        return $this->belongsToMany(MealPlan::class, 'meal_plan_member')
            ->withPivot('portion_multiplier')
            ->withTimestamps();
    }
}
