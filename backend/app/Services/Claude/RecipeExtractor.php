<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\DietType;
use App\Enums\ProteinSource;
use App\Enums\RecipeBase;
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
        //
        // The SDK already retries 429/5xx responses on its own (including
        // Anthropic's transient 503 "overloaded_error" — e.g. "Grammar
        // compilation is temporarily unavailable" — which structured-output
        // requests can hit), but its default of 2 retries with an 0.5s
        // initial/8s max backoff gives up within a few seconds. That's too
        // short to ride out a longer-lived overload incident, so a recipe
        // import failed outright instead of quietly succeeding a bit later.
        // Non-retryable errors (4xx like a malformed schema or bad request)
        // are unaffected — the SDK's retry policy already excludes those.
        $this->client = new Client(
            apiKey: config('services.anthropic.key'),
            requestOptions: [
                'transporter' => new GuzzleClient([
                    'connect_timeout' => 10,
                    'timeout' => 120,
                ]),
                'maxRetries' => 5,
                'initialRetryDelay' => 2.0,
                'maxRetryDelay' => 15.0,
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

    /**
     * Classifies an existing recipe's category, dominant base ingredient and
     * protein source from its title and ingredient list alone — in one call,
     * so a backfill over many existing recipes doesn't need three separate
     * API calls per recipe. Same rationale as {@see classifyDietType()}: a
     * cheap, focused judgment call over a short ingredient list rather than
     * a full re-extraction.
     *
     * @param  list<string>  $ingredientNames
     * @return array{category: RecipeCategory, base: RecipeBase, protein_source: ProteinSource}
     */
    public function classifyBaseAndProtein(string $title, array $ingredientNames): array
    {
        $response = $this->client->messages->create(
            model: 'claude-sonnet-5',
            maxTokens: 256,
            messages: [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $this->buildBaseAndProteinPrompt($title, $ingredientNames)],
                ]],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $this->baseAndProteinSchema()],
            ],
        );

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, associative: true, flags: JSON_THROW_ON_ERROR);

                return [
                    'category' => RecipeCategory::from($data['category']),
                    'base' => RecipeBase::from($data['base']),
                    'protein_source' => ProteinSource::from($data['protein_source']),
                ];
            }
        }

        throw new RuntimeException('Claude response contained no text block.');
    }

    private function buildBaseAndProteinPrompt(string $title, array $ingredientNames): string
    {
        $ingredients = implode(', ', $ingredientNames);

        return "Recipe title: {$title}\nIngredients: {$ingredients}\n\n"
            .'Classify the course/category, the dominant base ingredient (carbohydrate component), and the '
            .'dominant protein source of this dish, strictly from the literal title and ingredients list above.';
    }

    private function baseAndProteinSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => array_column(RecipeCategory::cases(), 'value'),
                    'description' => 'The best-fitting course/category for this dish: appetizer (Vorspeise), main_course (Hauptspeise), side_dish (Beilage), dessert (Dessert), snack (Snack), or drink (Getränk).',
                ],
                'base' => [
                    'type' => 'string',
                    'enum' => array_column(RecipeBase::cases(), 'value'),
                    'description' => 'The dominant carbohydrate/base component of the dish: pasta, reis (rice), kartoffeln (potatoes), huelsenfruechte (legumes/lentils/beans as the base), brot_gebaeck (bread/pastry-based), getreide_sonstiges (other grains, e.g. couscous, bulgur, quinoa), gemuese (vegetable-based with no starchy base), or sonstiges (none of the above fits, e.g. a pure protein dish or a soup).',
                ],
                'protein_source' => [
                    'type' => 'string',
                    'enum' => array_column(ProteinSource::cases(), 'value'),
                    'description' => 'The dominant protein source: huhn_gefluegel (chicken/poultry), rind (beef), schwein (pork), fisch_meeresfruechte (fish/seafood), tofu_seitan, huelsenfruechte (legumes/lentils/beans as protein), ei (egg as main protein), milchprodukte_kaese (dairy/cheese as main protein), or kein_hauptprotein (no significant protein source, e.g. a plain side dish or dessert).',
                ],
            ],
            'required' => ['category', 'base', 'protein_source'],
            'additionalProperties' => false,
        ];
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
                'base' => [
                    'type' => 'string',
                    'enum' => array_column(RecipeBase::cases(), 'value'),
                    'description' => 'The dominant carbohydrate/base component of the dish: pasta, reis (rice), kartoffeln (potatoes), huelsenfruechte (legumes/lentils/beans as the base), brot_gebaeck (bread/pastry-based), getreide_sonstiges (other grains, e.g. couscous, bulgur, quinoa), gemuese (vegetable-based with no starchy base), or sonstiges (none of the above fits, e.g. a pure protein dish or a soup).',
                ],
                'protein_source' => [
                    'type' => 'string',
                    'enum' => array_column(ProteinSource::cases(), 'value'),
                    'description' => 'The dominant protein source: huhn_gefluegel (chicken/poultry), rind (beef), schwein (pork), fisch_meeresfruechte (fish/seafood), tofu_seitan, huelsenfruechte (legumes/lentils/beans as protein), ei (egg as main protein), milchprodukte_kaese (dairy/cheese as main protein), or kein_hauptprotein (no significant protein source, e.g. a plain side dish or dessert).',
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
            'required' => ['title', 'category', 'diet_type', 'base', 'protein_source', 'servings', 'instructions', 'ingredients'],
            'additionalProperties' => false,
        ];
    }
}
