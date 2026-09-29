<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\DietType;
use RuntimeException;

/**
 * Translates a user's free-text meal-plan criteria (e.g. "diese Woche mal
 * was Asiatisches, nichts mit Pilzen") into structured constraints that the
 * deterministic {@see \App\Services\MealPlanning\RecipeScorer} can apply.
 * This is deliberately a single, cheap classification call — the actual
 * recipe selection never goes through the LLM, so it has no opportunity to
 * repeat itself or drift toward statistically "typical" choices.
 */
class MealPlanConstraintExtractor
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(apiKey: config('services.anthropic.key'));
    }

    /**
     * @return array{
     *     cuisines_prefer: list<string>,
     *     cuisines_avoid: list<string>,
     *     exclude_ingredients: list<string>,
     *     max_prep_minutes: int|null,
     *     dietary_requirement: DietType|null,
     *     notes: string|null,
     * }
     */
    public function extract(?string $criteria): array
    {
        $criteria = trim((string) $criteria);

        if ($criteria === '') {
            return [
                'cuisines_prefer' => [],
                'cuisines_avoid' => [],
                'exclude_ingredients' => [],
                'max_prep_minutes' => null,
                'dietary_requirement' => null,
                'notes' => null,
            ];
        }

        $response = $this->client->messages->create(
            model: 'claude-sonnet-5',
            maxTokens: 512,
            messages: [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $this->buildPrompt($criteria)],
                ]],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);

                return [
                    'cuisines_prefer' => $data['cuisines_prefer'] ?? [],
                    'cuisines_avoid' => $data['cuisines_avoid'] ?? [],
                    'exclude_ingredients' => $data['exclude_ingredients'] ?? [],
                    'max_prep_minutes' => $data['max_prep_minutes'] ?? null,
                    'dietary_requirement' => isset($data['dietary_requirement'])
                        ? DietType::from($data['dietary_requirement'])
                        : null,
                    'notes' => $data['notes'] ?? null,
                ];
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    private function buildPrompt(string $criteria): string
    {
        return <<<PROMPT
            Übersetze die folgenden Wünsche einer Nutzerin/eines Nutzers für einen Wochen-Essensplan in
            strukturierte Einschränkungen. Nutze nur, was explizit oder eindeutig impliziert ist — erfinde
            keine zusätzlichen Einschränkungen.

            Wünsche: "{$criteria}"

            - cuisines_prefer / cuisines_avoid: Küchen als kurze deutsche Begriffe (z. B. "asiatisch",
              "italienisch"), leer wenn nichts genannt wird.
            - exclude_ingredients: konkrete auszuschließende Zutaten (z. B. "Pilze"), leer wenn keine genannt.
            - max_prep_minutes: nur setzen, wenn explizit eine Zeitobergrenze genannt wird (z. B. "schnell",
              "unter 30 Minuten" → 30), sonst null.
            - dietary_requirement: nur setzen, wenn explizit eine Ernährungsform gefordert wird, sonst null.
            - notes: kurzer Rest-Kontext, der sich nicht in die obigen Felder einordnen lässt, sonst null.
            PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cuisines_prefer' => ['type' => 'array', 'items' => ['type' => 'string']],
                'cuisines_avoid' => ['type' => 'array', 'items' => ['type' => 'string']],
                'exclude_ingredients' => ['type' => 'array', 'items' => ['type' => 'string']],
                'max_prep_minutes' => ['type' => ['integer', 'null']],
                'dietary_requirement' => [
                    'anyOf' => [
                        ['type' => 'string', 'enum' => array_column(DietType::cases(), 'value')],
                        ['type' => 'null'],
                    ],
                ],
                'notes' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'cuisines_prefer',
                'cuisines_avoid',
                'exclude_ingredients',
                'max_prep_minutes',
                'dietary_requirement',
                'notes',
            ],
            'additionalProperties' => false,
        ];
    }
}
