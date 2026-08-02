<?php

namespace App\Services\Claude;

use Anthropic\Client;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RecipeExtractor
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(apiKey: config('services.anthropic.key'));
    }

    public function extractFromText(string $text): array
    {
        return $this->extract([
            ['type' => 'text', 'text' => $this->buildPrompt($text)],
        ]);
    }

    public function extractFromUrl(string $url): array
    {
        $html = Http::timeout(15)->get($url)->body();
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $text = mb_substr($text, 0, 15000);

        return $this->extract([
            ['type' => 'text', 'text' => $this->buildPrompt($text, $url)],
        ]);
    }

    public function extractFromImage(string $base64, string $mediaType): array
    {
        return $this->extract([
            [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64],
            ],
            ['type' => 'text', 'text' => $this->buildPrompt(null)],
        ]);
    }

    private function buildPrompt(?string $sourceText, ?string $sourceUrl = null): string
    {
        $intro = match (true) {
            $sourceUrl !== null => "Extract the recipe from this webpage content (source: {$sourceUrl}):",
            $sourceText !== null => 'Extract the recipe from this text:',
            default => 'Extract the recipe shown in this image.',
        };

        $body = $sourceText !== null ? "\n\n{$sourceText}" : '';

        return "{$intro}{$body}\n\nReturn quantities in metric units (g, ml, Stück) where the source allows it. Omit or use null for anything that cannot be determined from the source.";
    }

    private function extract(array $userContent): array
    {
        $response = $this->client->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 4096,
            messages: [
                ['role' => 'user', 'content' => $userContent],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                return json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'cuisine' => ['type' => ['string', 'null'], 'description' => 'e.g. vietnamesisch, japanisch, thailändisch, italienisch, deutsch'],
                'description' => ['type' => ['string', 'null']],
                'servings' => ['type' => 'integer'],
                'prep_time_minutes' => ['type' => ['integer', 'null']],
                'cook_time_minutes' => ['type' => ['integer', 'null']],
                'calories_per_serving' => ['type' => ['integer', 'null'], 'description' => 'kcal per serving'],
                'protein_per_serving_g' => ['type' => ['number', 'null']],
                'carbs_per_serving_g' => ['type' => ['number', 'null']],
                'fat_per_serving_g' => ['type' => ['number', 'null']],
                'instructions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Ordered list of preparation steps',
                ],
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => ['type' => 'string'],
                            'notes' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['name', 'quantity', 'unit'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'servings', 'instructions', 'ingredients'],
            'additionalProperties' => false,
        ];
    }
}
