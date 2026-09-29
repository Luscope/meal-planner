<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\RecipeSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'recipe_id',
        'status',
        'source_type',
        'source_url',
        'source_text',
        'image_path',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'source_type' => RecipeSourceType::class,
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

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
