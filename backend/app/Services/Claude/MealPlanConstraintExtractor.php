<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\DietType;
use App\Enums\ProteinSource;
use RuntimeException;

/**
 * Translates a user's free-text meal-plan criteria (e.g. "diese Woche mal
 * was Asiatisches, nichts mit Pilzen, freitags Fisch") into structured
 * constraints that the deterministic {@see \App\Services\MealPlanning\RecipeScorer}
 * can apply. This is deliberately a single, cheap classification call — the
 * actual recipe selection never goes through the LLM, so it has no
 * opportunity to repeat itself or drift toward statistically "typical"
 * choices.
 *
 * `day_overrides` carries weekday-pinned wishes ("freitags Fisch", "montags
 * schnell") separately from the week-wide constraints, since those need to
 * apply only to the slot that actually falls on that weekday.
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
     *     day_overrides: list<array{weekday: string, cuisines_prefer: list<string>, protein_source_prefer: list<string>, max_prep_minutes: int|null}>,
     *     plan_days: int|null,
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
                'day_overrides' => [],
                'plan_days' => null,
                'notes' => null,
            ];
        }

        $response = $this->client->messages->create(
            model: 'claude-sonnet-5',
            maxTokens: 768,
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
                    'day_overrides' => array_map(fn ($override) => [
                        'weekday' => $override['weekday'],
                        'cuisines_prefer' => $override['cuisines_prefer'] ?? [],
                        // Round-tripped through the enum purely to validate the value —
                        // RecipeScorer compares plain strings against recipe.protein_source.
                        'protein_source_prefer' => array_map(
                            fn ($value) => ProteinSource::from($value)->value,
                            $override['protein_source_prefer'] ?? []
                        ),
                        'max_prep_minutes' => $override['max_prep_minutes'] ?? null,
                    ], $data['day_overrides'] ?? []),
                    'plan_days' => $data['plan_days'] ?? null,
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
              "italienisch"), die für die GANZE Woche gelten sollen, leer wenn nichts genannt wird.
            - exclude_ingredients: konkrete auszuschließende Zutaten (z. B. "Pilze"), leer wenn keine genannt.
            - max_prep_minutes: nur setzen, wenn explizit eine wochenweite Zeitobergrenze genannt wird
              (z. B. "immer schnell" → 30), sonst null.
            - dietary_requirement: nur setzen, wenn explizit eine Ernährungsform gefordert wird, sonst null.
            - day_overrides: Wünsche, die sich auf einen BESTIMMTEN Wochentag beziehen (z. B. "freitags
              Fisch", "montags schnell", "am Mittwoch etwas Italienisches") — ein Eintrag pro genannten
              Wochentag, leer wenn kein Wochentag explizit genannt wird:
              - weekday: monday/tuesday/wednesday/thursday/friday/saturday/sunday
              - cuisines_prefer: Küchen nur für diesen Tag, sonst leer
              - protein_source_prefer: Proteinquelle(n) nur für diesen Tag (z. B. "Fisch" →
                fisch_meeresfruechte), sonst leer
              - max_prep_minutes: Zeitobergrenze nur für diesen Tag, sonst null
            - plan_days: nur setzen, wenn explizit eine Anzahl an Tagen genannt wird, für die überhaupt
              geplant werden soll (z. B. "nur für zwei Tage", "für die nächsten 3 Tage" → 3) — NICHT zu
              verwechseln mit max_prep_minutes (das ist eine Zeitobergrenze pro Gericht). Sonst null.
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
                'day_overrides' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'weekday' => [
                                'type' => 'string',
                                'enum' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                            ],
                            'cuisines_prefer' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'protein_source_prefer' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => array_column(ProteinSource::cases(), 'value')],
                            ],
                            'max_prep_minutes' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['weekday', 'cuisines_prefer', 'protein_source_prefer', 'max_prep_minutes'],
                        'additionalProperties' => false,
                    ],
                ],
                'plan_days' => ['type' => ['integer', 'null']],
                'notes' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'cuisines_prefer',
                'cuisines_avoid',
                'exclude_ingredients',
                'max_prep_minutes',
                'dietary_requirement',
                'day_overrides',
                'plan_days',
                'notes',
            ],
            'additionalProperties' => false,
        ];
    }
}
