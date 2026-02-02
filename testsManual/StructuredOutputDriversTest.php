<?php

/**
 * Manual Test: Structured Output across Drivers
 *
 * This test verifies that structured output works correctly with:
 * 1. DataModel-based schemas
 * 2. Manually defined array schemas
 *
 * Tests OpenAI, Gemini, Groq, and Claude drivers.
 *
 * Prerequisites:
 * - Set OPENAI_API_KEY in openai-api-key.php
 * - Run: php testsManual/StructuredOutputDriversTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use LarAgent\Agent;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Attributes\Desc;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\Gemini\GeminiDriver;
use LarAgent\Drivers\Groq\GroqDriver;
use LarAgent\Drivers\OpenAi\OpenAiDriver;

// Bootstrap minimal Laravel environment
$container = new Container;
Container::setInstance($container);
$container->singleton('events', fn () => new Dispatcher($container));
$container->singleton('config', fn () => new \Illuminate\Config\Repository);
Facade::setFacadeApplication($container);

// Load API keys
$apiKey = include __DIR__.'/openai-api-key.php';
$geminiKey = include __DIR__.'/gemini-api-key.php';
$groqKey = include __DIR__.'/groq-api-key.php';
$claudeKey = include __DIR__.'/anthropic-api-key.php';

if (empty($apiKey)) {
    echo "❌ Error: Please set your OpenAI API key in openai-api-key.php\n";
    exit(1);
}

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║        Structured Output Drivers Test Suite               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Configure providers
config()->set('laragent.fallback_provider', 'openai');
config()->set('laragent.providers.openai', [
    'label' => 'openai',
    'model' => 'gpt-4o-mini',
    'api_key' => $apiKey,
    'driver' => OpenAiDriver::class,
]);
config()->set('laragent.providers.gemini', [
    'label' => 'gemini',
    'model' => 'gemini-1.5-flash-latest',
    'api_key' => $geminiKey ?: null,
    'driver' => GeminiDriver::class,
]);
config()->set('laragent.providers.groq', [
    'label' => 'groq',
    'model' => 'llama-3.3-70b-versatile',
    'api_key' => $groqKey ?: null,
    'driver' => GroqDriver::class,
]);
config()->set('laragent.providers.claude', [
    'label' => 'claude',
    'model' => 'claude-3-7-sonnet-latest',
    'api_key' => $claudeKey ?: null,
    'driver' => ClaudeDriver::class,
]);

config()->set('laragent.storage.default_history_storage', [
    \LarAgent\Context\Drivers\InMemoryStorage::class,
]);
config()->set('laragent.storage.default_storage', [
    \LarAgent\Context\Drivers\InMemoryStorage::class,
]);

// DataModel for testing
#[Desc('A person with name and age')]
class PersonInfo extends DataModel
{
    #[Desc('The name of the person')]
    public string $name;

    #[Desc('The age of the person in years')]
    public int $age;

    #[Desc('The city where the person lives')]
    public string $city;
}

// Manual array schema for comparison
$manualSchema = [
    'title' => 'person_info',  // Should be used as name
    'type' => 'object',
    'properties' => [
        'name' => [
            'type' => 'string',
            'description' => 'The name of the person',
        ],
        'age' => [
            'type' => 'integer',
            'description' => 'The age of the person in years',
        ],
        'city' => [
            'type' => 'string',
            'description' => 'The city where the person lives',
        ],
    ],
    'required' => ['name', 'age', 'city'],
    'additionalProperties' => false,  // Should be preserved
];

// Pre-wrapped schema (OpenAI format)
$wrappedSchema = [
    'name' => 'custom_person_schema',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'city' => ['type' => 'string'],
        ],
        'required' => ['name', 'age', 'city'],
    ],
    'strict' => true,
];

// Agent with DataModel schema
class DataModelSchemaAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $responseSchema = PersonInfo::class;

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Agent with manual array schema
class ManualSchemaAgent extends Agent
{
    protected $model = 'gpt-4o-mini';

    protected $provider = 'openai';

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Gemini Agent
class GeminiSchemaAgent extends Agent
{
    protected $model = 'gemini-1.5-flash-latest';

    protected $provider = 'gemini';

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Gemini Agent with DataModel
class GeminiDataModelAgent extends Agent
{
    protected $model = 'gemini-1.5-flash-latest';

    protected $provider = 'gemini';

    protected $responseSchema = PersonInfo::class;

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Groq Agent
class GroqSchemaAgent extends Agent
{
    protected $model = 'llama-3.3-70b-versatile';

    protected $provider = 'groq';

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Groq Agent with DataModel
class GroqDataModelAgent extends Agent
{
    protected $model = 'llama-3.3-70b-versatile';

    protected $provider = 'groq';

    protected $responseSchema = PersonInfo::class;

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Claude Agent
class ClaudeSchemaAgent extends Agent
{
    protected $model = 'claude-3-7-sonnet-latest';

    protected $provider = 'claude';

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// Claude Agent with DataModel
class ClaudeDataModelAgent extends Agent
{
    protected $model = 'claude-3-7-sonnet-latest';

    protected $provider = 'claude';

    protected $responseSchema = PersonInfo::class;

    protected $storage = [\LarAgent\Context\Drivers\InMemoryStorage::class];

    protected $history = 'in_memory';

    public function instructions(): string
    {
        return 'Extract person information from the text. Return structured data.';
    }
}

// ============================================================================
// TEST 1: DataModel Schema with OpenAI
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 1: DataModel Schema with OpenAI\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $agent = DataModelSchemaAgent::make();
    $response = $agent->respond('John Smith is 35 years old and lives in New York City.');

    echo 'Response type: '.gettype($response)."\n";

    if ($response instanceof PersonInfo) {
        echo "✅ Got PersonInfo DataModel!\n";
        echo "   Name: {$response->name}\n";
        echo "   Age: {$response->age}\n";
        echo "   City: {$response->city}\n";
    } elseif (is_array($response)) {
        echo "✅ Got array response:\n";
        print_r($response);
    } else {
        echo 'Response: '.json_encode($response)."\n";
    }

    echo "\n✅ TEST 1 PASSED\n\n";
} catch (\Exception $e) {
    echo '❌ TEST 1 FAILED: '.$e->getMessage()."\n\n";
}

// ============================================================================
// TEST 2: Manual Array Schema with OpenAI
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 2: Manual Array Schema with OpenAI\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $agent = ManualSchemaAgent::make();
    $agent->responseSchema($manualSchema);

    echo "Schema title should be used as name: 'person_info'\n";
    echo "additionalProperties: false should be preserved\n\n";

    $response = $agent->respond('Maria Garcia is 28 years old and lives in Barcelona.');

    echo 'Response type: '.gettype($response)."\n";

    if (is_array($response)) {
        echo "✅ Got array response:\n";
        echo '   Name: '.($response['name'] ?? 'N/A')."\n";
        echo '   Age: '.($response['age'] ?? 'N/A')."\n";
        echo '   City: '.($response['city'] ?? 'N/A')."\n";
    } else {
        echo 'Response: '.json_encode($response)."\n";
    }

    echo "\n✅ TEST 2 PASSED\n\n";
} catch (\Exception $e) {
    echo '❌ TEST 2 FAILED: '.$e->getMessage()."\n\n";
}

// ============================================================================
// TEST 3: Pre-wrapped Schema with OpenAI
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 3: Pre-wrapped Schema with OpenAI\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $agent = ManualSchemaAgent::make();
    $agent->responseSchema($wrappedSchema);

    echo "Pre-wrapped schema name: 'custom_person_schema'\n";
    echo "Strict mode: true (should be preserved)\n\n";

    $response = $agent->respond('Kenji Tanaka is 42 years old and lives in Tokyo.');

    echo 'Response type: '.gettype($response)."\n";

    if (is_array($response)) {
        echo "✅ Got array response:\n";
        echo '   Name: '.($response['name'] ?? 'N/A')."\n";
        echo '   Age: '.($response['age'] ?? 'N/A')."\n";
        echo '   City: '.($response['city'] ?? 'N/A')."\n";
    } else {
        echo 'Response: '.json_encode($response)."\n";
    }

    echo "\n✅ TEST 3 PASSED\n\n";
} catch (\Exception $e) {
    echo '❌ TEST 3 FAILED: '.$e->getMessage()."\n\n";
}

// ============================================================================
// TEST 4: Gemini Driver (if API key available)
// ============================================================================
if ($geminiKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 4: Manual Array Schema with Gemini\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = GeminiSchemaAgent::make();
        $agent->responseSchema($manualSchema);

        $response = $agent->respond('Anna Müller is 31 years old and lives in Berlin.');

        echo 'Response type: '.gettype($response)."\n";

        if (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 4 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 4 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 4: Gemini - SKIPPED (gemini-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 5: Groq Driver (if API key available)
// ============================================================================
if ($groqKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 5: Manual Array Schema with Groq\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = GroqSchemaAgent::make();
        $agent->responseSchema($manualSchema);

        $response = $agent->respond('Pierre Dubois is 55 years old and lives in Paris.');

        echo 'Response type: '.gettype($response)."\n";

        if (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 5 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 5 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 5: Groq - SKIPPED (groq-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 6: DataModel Schema with Gemini (if API key available)
// ============================================================================
if ($geminiKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 6: DataModel Schema with Gemini\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = GeminiDataModelAgent::make();
        $response = $agent->respond('Sakura Yamamoto is 27 years old and lives in Osaka.');

        echo 'Response type: '.gettype($response)."\n";

        if ($response instanceof PersonInfo) {
            echo "✅ Got PersonInfo DataModel!\n";
            echo "   Name: {$response->name}\n";
            echo "   Age: {$response->age}\n";
            echo "   City: {$response->city}\n";
        } elseif (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 6 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 6 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 6: Gemini DataModel - SKIPPED (gemini-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 7: DataModel Schema with Groq (if API key available)
// ============================================================================
if ($groqKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 7: DataModel Schema with Groq\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = GroqDataModelAgent::make();
        $response = $agent->respond('Carlos Silva is 39 years old and lives in São Paulo.');

        echo 'Response type: '.gettype($response)."\n";

        if ($response instanceof PersonInfo) {
            echo "✅ Got PersonInfo DataModel!\n";
            echo "   Name: {$response->name}\n";
            echo "   Age: {$response->age}\n";
            echo "   City: {$response->city}\n";
        } elseif (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 7 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 7 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 7: Groq DataModel - SKIPPED (groq-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 8: DataModel Hydration - Response to DataModel Instance
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 8: DataModel Hydration - Array Response to DataModel Instance\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    // Use OpenAI for this test
    $agent = DataModelSchemaAgent::make();
    $response = $agent->respond('Emma Watson is 33 years old and lives in London.');

    echo "1. Raw API Response:\n";
    echo '   Type: '.gettype($response)."\n";

    if (is_array($response)) {
        echo '   Data: '.json_encode($response)."\n\n";

        // Hydrate array into DataModel instance using fromArray()
        echo "2. Creating DataModel instance from response using PersonInfo::fromArray()...\n";
        $person = PersonInfo::fromArray($response);

        echo "\n3. Accessing via DataModel properties:\n";
        echo "   \$person->name = '{$person->name}'\n";
        echo "   \$person->age  = {$person->age}\n";
        echo "   \$person->city = '{$person->city}'\n";

        // Demonstrate type safety
        echo "\n4. Type verification:\n";
        echo '   gettype($person->name) = '.gettype($person->name)."\n";
        echo '   gettype($person->age)  = '.gettype($person->age)."\n";
        echo '   gettype($person->city) = '.gettype($person->city)."\n";

        // Demonstrate toArray()
        echo "\n5. DataModel->toArray():\n";
        print_r($person->toArray());

        echo "\n✅ TEST 8 PASSED\n\n";
    } else {
        echo "❌ TEST 8 FAILED: Expected array response\n\n";
    }
} catch (\Exception $e) {
    echo '❌ TEST 8 FAILED: '.$e->getMessage()."\n\n";
}

// ============================================================================
// TEST 9: Gemini unwrapResponseSchema - OpenAI-style schema with Gemini
// ============================================================================
if ($geminiKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 9: Gemini with OpenAI-style Wrapped Schema\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        // OpenAI-style wrapped schema (what OpenAI requires)
        $openAiStyleSchema = [
            'name' => 'person_data',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The person name'],
                    'age' => ['type' => 'integer', 'description' => 'The person age'],
                    'city' => ['type' => 'string', 'description' => 'The city'],
                ],
                'required' => ['name', 'age', 'city'],
                'additionalProperties' => false,
            ],
        ];

        echo "Input schema (OpenAI-style wrapped):\n";
        echo json_encode($openAiStyleSchema, JSON_PRETTY_PRINT)."\n\n";
        echo "Gemini should unwrap this and use only the inner 'schema' object.\n\n";

        $agent = GeminiSchemaAgent::make();
        $agent->responseSchema($openAiStyleSchema);

        $response = $agent->respond('Hans Schmidt is 45 years old and lives in Munich.');

        echo 'Response type: '.gettype($response)."\n";

        if (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
            echo "\n✅ TEST 9 PASSED - unwrapResponseSchema works correctly!\n\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
            echo "\n❌ TEST 9 FAILED: Expected array response\n\n";
        }
    } catch (\Exception $e) {
        echo '❌ TEST 9 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 9: Gemini unwrapResponseSchema - SKIPPED (gemini-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 10: Claude with Manual Array Schema (if API key available)
// ============================================================================
if ($claudeKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 10: Manual Array Schema with Claude\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = ClaudeSchemaAgent::make();
        $agent->responseSchema($manualSchema);

        $response = $agent->respond('Alice Johnson is 29 years old and lives in Seattle.');

        echo 'Response type: '.gettype($response)."\n";

        if (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 10 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 10 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 10: Claude - SKIPPED (anthropic-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 11: Claude with DataModel Schema (if API key available)
// ============================================================================
if ($claudeKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 11: DataModel Schema with Claude\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = ClaudeDataModelAgent::make();
        $response = $agent->respond('Robert Chen is 44 years old and lives in San Francisco.');

        echo 'Response type: '.gettype($response)."\n";

        if ($response instanceof PersonInfo) {
            echo "✅ Got PersonInfo DataModel!\n";
            echo "   Name: {$response->name}\n";
            echo "   Age: {$response->age}\n";
            echo "   City: {$response->city}\n";
        } elseif (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
        }

        echo "\n✅ TEST 11 PASSED\n\n";
    } catch (\Exception $e) {
        echo '❌ TEST 11 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 11: Claude DataModel - SKIPPED (anthropic-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// ============================================================================
// TEST 12: Claude with OpenAI-wrapped Schema (if API key available)
// ============================================================================
if ($claudeKey) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 12: Claude with OpenAI-style Wrapped Schema\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        $agent = ClaudeSchemaAgent::make();
        $agent->responseSchema($wrappedSchema);

        echo "Input schema (OpenAI-style wrapped):\n";
        echo "Claude should unwrap this and use only the inner 'schema' object.\n\n";

        $response = $agent->respond('Isabella Rodriguez is 31 years old and lives in Madrid.');

        echo 'Response type: '.gettype($response)."\n";

        if (is_array($response)) {
            echo "✅ Got array response:\n";
            echo '   Name: '.($response['name'] ?? 'N/A')."\n";
            echo '   Age: '.($response['age'] ?? 'N/A')."\n";
            echo '   City: '.($response['city'] ?? 'N/A')."\n";
            echo "\n✅ TEST 12 PASSED - unwrapResponseSchema works correctly!\n\n";
        } else {
            echo 'Response: '.json_encode($response)."\n";
            echo "\n❌ TEST 12 FAILED: Expected array response\n\n";
        }
    } catch (\Exception $e) {
        echo '❌ TEST 12 FAILED: '.$e->getMessage()."\n\n";
    }
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST 12: Claude unwrapResponseSchema - SKIPPED (anthropic-api-key.php not set)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "ALL TESTS COMPLETE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
