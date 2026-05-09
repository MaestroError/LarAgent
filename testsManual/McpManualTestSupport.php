<?php

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Agent;
use LarAgent\Context\Drivers\InMemoryStorage;
use LarAgent\History\InMemoryChatHistory;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;
use LarAgent\Tool;

$GLOBALS['laragent_manual_test_config'] = [];

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['laragent_manual_test_config'][$key] ?? $default;
}

function mcpManualSetConfig(array $config): void
{
    $GLOBALS['laragent_manual_test_config'] = $config;
}

function mcpManualBaseConfig(array $mcpServers): array
{
    return [
        'laragent.default_driver' => FakeLlmDriver::class,
        'laragent.default_chat_history' => InMemoryChatHistory::class,
        'laragent.default_history_storage' => 'in_memory',
        'laragent.default_storage' => [InMemoryStorage::class],
        'laragent.fallback_provider' => null,
        'laragent.providers.default' => [
            'label' => 'default',
            'driver' => FakeLlmDriver::class,
            'model' => 'manual-mcp-test',
            'api_key' => 'manual-mcp-test',
        ],
        'laragent.mcp_tool_caching' => ['enabled' => false],
        'laragent.mcp_servers' => $mcpServers,
    ];
}

function mcpManualAgent(string $key, array $serverNames): Agent
{
    return new class($key, $serverNames) extends Agent
    {
        protected $driver = FakeLlmDriver::class;

        protected $history = 'in_memory';

        protected array $registeredMcpServers = [];

        public function __construct(string $key, array $serverNames)
        {
            $this->registeredMcpServers = $serverNames;

            parent::__construct($key);
        }

        public function instructions()
        {
            return 'Manual MCP integration test agent.';
        }

        public function registerMcpServers()
        {
            return $this->registeredMcpServers;
        }
    };
}

function mcpManualPrintHeader(string $title): void
{
    echo "\n".str_repeat('=', 72)."\n";
    echo $title."\n";
    echo str_repeat('=', 72)."\n\n";
}

function mcpManualPrintInfo(string $message): void
{
    echo '[INFO] '.$message."\n";
}

function mcpManualPrintSuccess(string $message): void
{
    echo '[PASS] '.$message."\n";
}

function mcpManualPrintError(string $message): void
{
    echo '[FAIL] '.$message."\n";
}

function mcpManualAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function mcpManualToolNames(array $tools): array
{
    return array_map(fn (Tool $tool) => $tool->getName(), $tools);
}

function mcpManualPrintToolList(array $tools): void
{
    foreach ($tools as $tool) {
        $required = $tool->getRequired();
        $suffix = empty($required) ? '' : ' (required: '.implode(', ', $required).')';
        echo ' - '.$tool->getName().$suffix."\n";
    }
}

function mcpManualFindTool(array $tools, array $patterns): ?Tool
{
    foreach ($patterns as $pattern) {
        $pattern = strtolower($pattern);
        foreach ($tools as $tool) {
            $name = strtolower($tool->getName());
            if ($name === $pattern || str_contains($name, $pattern)) {
                return $tool;
            }
        }
    }

    return null;
}

function mcpManualFindToolContainingAll(array $tools, array $terms): ?Tool
{
    foreach ($tools as $tool) {
        $name = strtolower($tool->getName());
        $matches = true;

        foreach ($terms as $term) {
            if (! str_contains($name, strtolower($term))) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $tool;
        }
    }

    return null;
}

function mcpManualFirstToolWithoutRequiredArgs(array $tools): ?Tool
{
    foreach ($tools as $tool) {
        if ($tool->getRequired() === []) {
            return $tool;
        }
    }

    return null;
}

function mcpManualBuildInput(Tool $tool, array $overrides = []): array
{
    $input = [];
    $properties = $tool->getProperties();

    foreach ($properties as $name => $schema) {
        if (array_key_exists($name, $overrides)) {
            $input[$name] = $overrides[$name];

            continue;
        }

        if (! in_array($name, $tool->getRequired(), true)) {
            continue;
        }

        $input[$name] = mcpManualSampleValue($name, is_array($schema) ? $schema : []);
    }

    foreach ($overrides as $name => $value) {
        if (array_key_exists($name, $properties) && ! array_key_exists($name, $input)) {
            $input[$name] = $value;
        }
    }

    return $input;
}

function mcpManualSampleValue(string $name, array $schema): mixed
{
    if (isset($schema['enum']) && is_array($schema['enum']) && isset($schema['enum'][0])) {
        return $schema['enum'][0];
    }

    $lowerName = strtolower($name);
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
        $type = $type[0] ?? 'string';
    }

    return match ($type) {
        'integer', 'number' => str_contains($lowerName, 'limit') ? 3 : 1,
        'boolean' => false,
        'array', 'object' => [],
        default => mcpManualSampleString($lowerName),
    };
}

function mcpManualSampleString(string $name): string
{
    return match (true) {
        str_contains($name, 'url'), str_contains($name, 'uri') => 'https://example.com',
        str_contains($name, 'library') => 'laravel',
        str_contains($name, 'query'), str_contains($name, 'search') => 'laravel',
        str_contains($name, 'topic') => 'routing',
        str_contains($name, 'path') => '/',
        str_contains($name, 'selector') => 'body',
        str_contains($name, 'title') => 'Example page',
        default => 'test',
    };
}

function mcpManualExecuteTool(Tool $tool, array $input = []): array
{
    mcpManualPrintInfo('Executing tool '.$tool->getName().' with input '.json_encode($input, JSON_UNESCAPED_SLASHES));

    $raw = $tool->execute($input);
    $decoded = $raw;

    if (is_string($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decoded = $json;
        }
    }

    return [
        'raw' => $raw,
        'decoded' => $decoded,
    ];
}

function mcpManualPrintResult(string $label, mixed $result): void
{
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = var_export($result, true);
    }

    if (strlen($json) > 1500) {
        $json = substr($json, 0, 1500)."\n...[truncated]";
    }

    echo $label.":\n".$json."\n";
}

function mcpManualLoadContext7ApiKey(): string
{
    $apiKey = trim((string) getenv('CONTEXT7_API_KEY'));
    if ($apiKey !== '') {
        return $apiKey;
    }

    $keyFile = __DIR__.'/context7-api-key.php';
    if (is_file($keyFile)) {
        $loadedKey = include $keyFile;
        if (is_string($loadedKey)) {
            return trim($loadedKey);
        }
    }

    return '';
}
