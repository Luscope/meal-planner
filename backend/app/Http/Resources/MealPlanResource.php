<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealPlanResource extends JsonResource
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
            'date' => $this->date->toDateString(),
            'meal_type' => $this->meal_type,
            'planned_servings' => $this->planned_servings,
            'recipe' => $this->whenLoaded('recipe', fn () => [
                'id' => $this->recipe->id,
                'title' => $this->recipe->title,
                'cuisine' => $this->recipe->cuisine,
                'servings' => $this->recipe->servings,
                'calories_per_serving' => $this->recipe->calories_per_serving,
                'protein_per_serving_g' => $this->recipe->protein_per_serving_g,
            ]),
            'family_members' => $this->whenLoaded('familyMembers', fn () => $this->familyMembers->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'portion_multiplier' => $member->pivot->portion_multiplier,
            ])),
        ];
    }
}
