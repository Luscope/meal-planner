<?php

namespace App\Services\Claude;

use Anthropic\Client;
use RuntimeException;

class MealPlanSuggester
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(apiKey: config('services.anthropic.key'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipes
     * @param  array<int, array<string, mixed>>  $familyMembers
     * @param  array<int, array{date: string, meal_type: string}>  $slots
     * @return array<int, array<string, mixed>>
     */
    public function suggest(array $recipes, array $familyMembers, array $slots, ?string $criteria): array
    {
        $response = $this->client->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 4096,
            messages: [
                ['role' => 'user', 'content' => $this->buildPrompt($recipes, $familyMembers, $slots, $criteria)],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);

                return $decoded['assignments'];
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    private function buildPrompt(array $recipes, array $familyMembers, array $slots, ?string $criteria): string
    {
        $recipesJson = json_encode($recipes);
        $membersJson = json_encode($familyMembers);
        $slotsJson = json_encode($slots);

        $criteriaText = $criteria
            ? "Zusätzliche Kriterien der Nutzerin/des Nutzers: {$criteria}"
            : 'Keine besonderen Kriterien angegeben — plane ausgewogen und abwechslungsreich.';

        return <<<PROMPT
            Du planst einen Wochenplan für eine Familie. Wähle für jeden der folgenden freien Termine
            (Datum + Mahlzeit) genau ein Rezept aus der verfügbaren Liste aus und entscheide, welche
            Familienmitglieder mitessen.

            Verfügbare Rezepte (nur diese "id"-Werte als recipe_id verwenden):
            {$recipesJson}

            Familienmitglieder (nur diese "id"-Werte als family_member_id verwenden):
            {$membersJson}

            Zu füllende Termine (für jeden genau einen Eintrag erzeugen):
            {$slotsJson}

            {$criteriaText}

            Regeln:
            - Nutze ausschließlich recipe_id-Werte aus der Rezeptliste oben, keine erfundenen IDs.
            - Nutze ausschließlich family_member_id-Werte aus der Mitgliederliste oben.
            - Erzeuge für jeden angegebenen Termin genau einen Eintrag.
            - Vermeide es, dasselbe Rezept mehrmals in derselben Woche zu verwenden, außer es gibt zu
              wenige passende Rezepte für die gewünschte Vielfalt.
            - Standardmäßig essen alle Familienmitglieder mit, außer die Kriterien legen etwas anderes nahe.
            - portion_multiplier ist normalerweise 1.0; weiche nur davon ab, wenn die Kalorien-/Protein-Ziele
              eines Mitglieds das erkennbar nahelegen (z. B. 0.5 für ein Kind bei einem sehr kalorienreichen Gericht).
            PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'meal_type' => [
                                'type' => 'string',
                                'enum' => ['breakfast', 'lunch', 'dinner', 'snack'],
                            ],
                            'recipe_id' => ['type' => 'integer'],
                            'family_members' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'family_member_id' => ['type' => 'integer'],
                                        'portion_multiplier' => ['type' => 'number'],
                                    ],
                                    'required' => ['family_member_id', 'portion_multiplier'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['date', 'meal_type', 'recipe_id', 'family_members'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['assignments'],
            'additionalProperties' => false,
        ];
    }
}
