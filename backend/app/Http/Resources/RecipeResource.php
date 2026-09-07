<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'cuisine' => $this->cuisine,
            'category' => $this->category,
            'description' => $this->description,
            'servings' => $this->servings,
            'prep_time_minutes' => $this->prep_time_minutes,
            'cook_time_minutes' => $this->cook_time_minutes,
            'calories_per_serving' => $this->calories_per_serving,
            'protein_per_serving_g' => $this->protein_per_serving_g,
            'carbs_per_serving_g' => $this->carbs_per_serving_g,
            'fat_per_serving_g' => $this->fat_per_serving_g,
            'instructions' => $this->instructions,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'quantity' => $ingredient->pivot->quantity,
                'unit' => $ingredient->pivot->unit,
                'notes' => $ingredient->pivot->notes,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
