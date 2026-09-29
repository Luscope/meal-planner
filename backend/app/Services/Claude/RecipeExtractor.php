<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\DietType;
use App\Enums\RecipeCategory;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RecipeExtractor
{
    private Client $client;

    public function __construct()
    {
        // The Anthropic SDK's own `timeout` request option is advisory only
        // (never read internally) — it relies entirely on the transporter to
        // enforce it. Without an explicit one, a stalled connection can hang
        // indefinitely: PHP's pcntl-based job timeout can't reliably
        // interrupt a blocking curl call, which previously wedged the queue
        // worker until it was restarted manually. A pre-configured Guzzle
        // transporter enforces the timeout at the curl level instead.
        $this->client = new Client(
            apiKey: config('services.anthropic.key'),
            requestOptions: [
                'transporter' => new GuzzleClient([
                    'connect_timeout' => 10,
                    'timeout' => 120,
                ]),
            ],
        );
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

        if (mb_strlen($text) < 300) {
            throw new RuntimeException(
                'Die Seite lieferte kaum Text (nur '.mb_strlen($text).' Zeichen) — vermutlich verhindert ein '
                .'Cookie-Banner, eine Paywall oder clientseitiges Rendering den Zugriff auf den eigentlichen Inhalt.'
            );
        }

        return $this->extract([
            ['type' => 'text', 'text' => $this->buildPrompt($text, $url)],
        ]);
    }

    /**
     * Classifies an existing recipe's diet type from its title and
     * ingredient list alone. Deliberately a separate, cheaper/faster call
     * than the full extraction — this is a judgment over a short ingredient
     * list, not multi-field extraction from raw source text. Uses Sonnet
     * rather than Haiku: an earlier Haiku pass misclassified a dish as
     * omnivore purely because its German title happened to contain the
     * substring "Omni" (title: "Omnianische Sonnenboote", a butter/banana/
     * rum dessert with no meat or fish) — Sonnet handles the "ignore the
     * title wording" instruction below reliably in spot checks.
     *
     * @param  list<string>  $ingredientNames
     */
    public function classifyDietType(string $title, array $ingredientNames): DietType
    {
        $response = $this->client->messages->create(
            model: 'claude-sonnet-5',
            maxTokens: 256,
            messages: [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $this->buildDietTypePrompt($title, $ingredientNames)],
                ]],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $this->dietTypeSchema()],
            ],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);

                return DietType::from($data['diet_type']);
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    private function buildDietTypePrompt(string $title, array $ingredientNames): string
    {
        $ingredients = implode(', ', $ingredientNames);

        return "Recipe title: {$title}\nIngredients: {$ingredients}\n\n"
            .'Classify the most restrictive diet this dish qualifies for. Base this decision strictly on the '
            .'literal ingredients list above — ignore what the title sounds like or happens to contain '
            .'(titles are just names and may coincidentally contain misleading substrings).';
    }

    private function dietTypeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'diet_type' => [
                    'type' => 'string',
                    'enum' => array_column(DietType::cases(), 'value'),
                    'description' => 'vegan (no animal products at all, including dairy, eggs, honey), vegetarian (no meat or fish/seafood, but dairy/eggs/honey OK), pescetarian (no meat, but fish/seafood OK), or omnivore (contains meat or poultry).',
                ],
            ],
            'required' => ['diet_type'],
            'additionalProperties' => false,
        ];
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
                $data = json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);

                $this->assertPlausibleRecipe($data);

                return $data;
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    /**
     * The structured-output schema still leaves room for a technically valid
     * but meaningless response (e.g. a single placeholder ingredient with
     * quantity 0, name "unavailable") when Claude can't find a real recipe in
     * the source but has no way to signal that within a forced JSON response.
     *
     * Only reject when *every* ingredient has a non-positive quantity — real
     * recipes routinely include one or two unmeasured items (Wasser, Salz
     * nach Geschmack, Öl zum Braten), so a single zero is normal, but an
     * entirely zero-quantity ingredient list is the placeholder-response
     * fingerprint we're actually guarding against.
     */
    private function assertPlausibleRecipe(array $data): void
    {
        $hasReasonableQuantity = collect($data['ingredients'])
            ->contains(fn ($ingredient) => ($ingredient['quantity'] ?? 0) > 0);

        if (! $hasReasonableQuantity) {
            throw new RuntimeException('Claude konnte kein plausibles Rezept aus der Quelle extrahieren.');
        }
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'cuisine' => ['type' => ['string', 'null'], 'description' => 'e.g. vietnamesisch, japanisch, thailändisch, italienisch, deutsch'],
                'category' => [
                    'type' => 'string',
                    'enum' => array_column(RecipeCategory::cases(), 'value'),
                    'description' => 'The best-fitting course/category for this dish: appetizer (Vorspeise), main_course (Hauptspeise), side_dish (Beilage), dessert (Dessert), snack (Snack), or drink (Getränk). Pick the single best match even if not stated explicitly by the source.',
                ],
                'diet_type' => [
                    'type' => 'string',
                    'enum' => array_column(DietType::cases(), 'value'),
                    'description' => 'The most restrictive diet this dish qualifies for, based on its ingredients: vegan (no animal products at all, including dairy, eggs, honey), vegetarian (no meat or fish/seafood, but dairy/eggs/honey OK), pescetarian (no meat, but fish/seafood OK), or omnivore (contains meat or poultry). Judge from the actual ingredient list, not the dish name.',
                ],
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
                    'items' => ['type' => 'string', 'minLength' => 1],
                    'minItems' => 1,
                    'description' => 'Ordered list of preparation steps',
                ],
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1],
                            'quantity' => ['type' => 'number'],
                            'unit' => ['type' => 'string', 'minLength' => 1],
                            'notes' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['name', 'quantity', 'unit'],
                        'additionalProperties' => false,
                    ],
                    'minItems' => 1,
                ],
            ],
            'required' => ['title', 'category', 'diet_type', 'servings', 'instructions', 'ingredients'],
            'additionalProperties' => false,
        ];
    }
}
