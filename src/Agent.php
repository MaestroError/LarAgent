<?php

namespace LarAgent;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use LarAgent\Attributes\Tool as ToolAttribute;
use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Context\Traits\HasContext;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\DTO\AgentDTO;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Core\Traits\Configs;
use LarAgent\Core\Traits\Events;
use LarAgent\Core\Traits\UsesCachedReflection;
use LarAgent\Core\Traits\UsesLogger;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\UserMessage;
use Redberry\MCPClient\MCPClient;

/**
 * Class Agent
 * For creating Ai Agent by extending this class
 * Only class dependant on Laravel
 */
class Agent
{
    use Configs;
    use Events;
    use HasContext;
    use UsesCachedReflection;
    use UsesLogger;

    // Agent properties

    protected LarAgent $agent;

    protected LlmDriverInterface $llmDriver;

    /**
     * When true respond() will return MessageInterface instance instead of
     * casting it to string or array.
     */
    protected bool $returnMessage = false;

    /** @var string|null */
    protected $message;

    /** @var UserMessage|null - ready made message to send to the agent */
    protected $readyMessage = null;

    /** @var string */
    protected $instructions;

    /** @var array|string|\LarAgent\Core\Contracts\DataModel|null - Array schema, DataModel class name, or DataModel instance */
    protected $responseSchema = null;

    /** @var array */
    protected $tools = [];

    /** @var array */
    protected $mcpServers = [];

    /** @var MCPClient */
    protected $mcpClient;

    /** @var array */
    protected $mcpConnections = [];

    /** @var string|array */
    protected $history;

    /** @var array */
    protected $storage;

    /** @var string */
    protected $driver;

    /**
     * Provider configuration - can be a string (single provider) or array (multiple with fallback).
     *
     * When array, first is primary, subsequent are fallback providers in priority order.
     * Per-provider overrides are supported via associative entries:
     * ['default', 'gemini' => ['model' => 'gemini-2.0'], 'claude']
     *
     * @var string|array
     */
    protected $provider = 'default';

    /**
     * Resolved list of provider configurations for fallback sequence.
     * Each entry contains: ['name' => string, 'config' => array]
     */
    protected array $providerList = [];

    /**
     * Index of the current provider in the provider list.
     */
    protected int $currentProviderIndex = 0;

    /** @var string */
    protected $providerName = '';

    /** @var bool */
    protected $developerRoleForInstructions = false;

    // Driver configs

    /** @var string */
    protected $model;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $apiUrl;

    /**
     * Truncation threshold (in tokens) for this agent.
     * When chat history exceeds this threshold, truncation strategies are applied.
     * If not set, uses provider's default_truncation_threshold config.
     * NOTE: This is NOT the model's context window - it should be set lower to leave
     * room for new messages and responses.
     *
     * @var int|null
     */
    protected $truncationThreshold;

    /**
     * Store message metadata with messages in chat history
     *
     * @var bool
     */
    protected $storeMeta;

    /**
     * Name of the agent
     * Basename of the class by default
     *
     * @var string
     */
    protected $name;

    /** @var int */
    protected $maxCompletionTokens;

    /** @var float */
    protected $temperature;

    /** @var int */
    protected $reinjectInstructionsPer;

    /** @var ?bool */
    protected $parallelToolCalls;

    /** @var string|array|null */
    protected $toolChoice;

    /** @var int|null */
    protected $n;

    /** @var float|null */
    protected $topP;

    /** @var float|null */
    protected $frequencyPenalty;

    /** @var float|null */
    protected $presencePenalty;

    /** @var bool */
    protected $toolCaching = false;

    /** @var int */
    protected $toolCacheTtl = 3600;

    /** @var string|null */
    protected $toolCacheStore = null;

    // Misc
    private array $builtInHistories = [
        'in_memory' => \LarAgent\Context\Drivers\InMemoryStorage::class,
        'session' => \LarAgent\Context\Drivers\SessionStorage::class,
        'cache' => \LarAgent\Context\Drivers\CacheStorage::class,
        'file' => \LarAgent\Context\Drivers\FileStorage::class,
        'json' => \LarAgent\Context\Drivers\FileStorage::class,
        'database' => \LarAgent\Context\Drivers\EloquentStorage::class,
        'database-simple' => \LarAgent\Context\Drivers\SimpleEloquentStorage::class,
    ];

    /** @var array */
    protected $images = [];

    /** @var array|null */
    protected $audioFiles = null;

    /** @var array */
    protected $modalities = [];

    /** @var array|null */
    protected $audio = null;

    /**
     * Force read history from storage drivers
     * On agent initialization
     *
     * @var bool
     */
    protected $forceReadHistory = false;

    /**
     * Force save history to storage drivers
     * After each agent response
     *
     * @var bool
     */
    protected $forceSaveHistory = false;

    /**
     * Force read context from storage drivers
     * On agent initialization
     *
     * @var bool
     */
    protected $forceReadContext = false;

    /**
     * Enable usage tracking to store token usage per response.
     * When enabled, usage is stored after each response via afterResponse hook.
     * Set to true/false to override config, or leave null to use config.
     *
     * @var bool|null
     */
    protected $trackUsage = null;

    /**
     * Storage drivers configuration for usage storage.
     * Can be array of driver classes or string alias from builtInUsageStorages.
     * If not set, uses provider's usage_storage, then default_usage_storage config, then defaultStorageDrivers.
     *
     * @var array|string|null
     */
    protected $usageStorage = null;

    /**
     * Built-in usage storage driver aliases.
     */
    private array $builtInUsageStorages = [
        'in_memory' => \LarAgent\Context\Drivers\InMemoryStorage::class,
        'session' => \LarAgent\Context\Drivers\SessionStorage::class,
        'cache' => \LarAgent\Context\Drivers\CacheStorage::class,
        'file' => \LarAgent\Context\Drivers\FileStorage::class,
        'database' => \LarAgent\Usage\Drivers\EloquentUsageDriver::class,
        'database-simple' => \LarAgent\Context\Drivers\SimpleEloquentStorage::class,
    ];

    /**
     * Enable context window truncation.
     * When enabled, truncation is applied before sending messages to LLM.
     * Priority: Agent property > Provider config > Global config
     *
     * @var bool|null
     */
    protected $enableTruncation = null;

    public function __construct($key, bool $usesUserId = false, ?string $group = null)
    {
        $this->usesUserId = $usesUserId;

        // Set group before identity is built (if provided)
        if ($group !== null) {
            $this->group = $group;
        }

        $this->setupProviderData();
        $this->setName();
        $this->setChatSessionId($key, $this->name());

        $defaultStorageDrivers = $this->defaultStorageDrivers();
        $this->setupContext($defaultStorageDrivers);

        if ($this->forceReadContext) {
            $this->readContext();
        }

        $this->setupChatHistory();

        if ($this->forceReadHistory) {
            $this->chatHistory()->read();
        }

        // Setup usage tracking if enabled
        if ($this->shouldTrackUsage()) {
            $this->setupUsageStorage();
        }

        // Setup truncation if enabled
        if ($this->shouldTruncate()) {
            $this->setupTruncation();
        }

        $this->setupToolCaching();
        $this->initMcpClient();

        $this->callEvent('onInitialize');
    }

    public function __destruct()
    {
        $this->cleanup();
        $this->onTerminate();
    }

    protected function cleanup()
    {
        // Save context (dirty tracking handled by storages, events handled safely by Context)
        $this->context()->save();
    }

    // Public API

    /**
     * Create an agent instance for a specific user
     *
     * @param  Authenticatable  $user  The user to create agent for
     */
    public static function forUser(Authenticatable $user): static
    {
        return static::forUserId($user->getAuthIdentifier());
    }

    /**
     * Create an agent instance for a specific user ID
     *
     * @param  string  $userId  The user ID to create agent for
     */
    public static function forUserId(string $userId): static
    {
        return new static($userId, usesUserId: true);
    }

    /**
     * Reconstruct an agent instance from a SessionIdentity.
     * Useful for operating on tracked storages without knowing the original creation method.
     *
     * @param  \LarAgent\Context\Contracts\SessionIdentity  $identity  The identity to reconstruct from
     * @return static The reconstructed agent instance
     */
    public static function fromIdentity(\LarAgent\Context\Contracts\SessionIdentity $identity): static
    {
        $group = $identity->getGroup();

        // Determine if this was a user-based or chat-based identity
        if ($identity->getUserId() !== null) {
            return new static($identity->getUserId(), usesUserId: true, group: $group);
        }

        return new static($identity->getChatName() ?? 'default', usesUserId: false, group: $group);
    }

    /**
     * Create an agent instance with a specific key
     *
     * @param  string|null  $key  The key to identify this agent instance
     */
    public static function for(?string $key = null): static
    {
        $key = $key ?? self::generateRandomKey();
        $instance = new static($key);

        return $instance;
    }

    /**
     * Set the message for the agent to process
     *
     * @param  string|MessageInterface  $message  The message to process
     */
    public function message(string|MessageInterface $message): static
    {
        if ($message instanceof MessageInterface) {
            $this->readyMessage = $message;
        } else {
            $this->message = $message;
        }

        return $this;
    }

    /**
     * Create a new agent instance.
     *
     * @param  string|null  $key  The key to identify this agent instance
     * @return static The created agent instance
     */
    public static function make(?string $key = null): static
    {
        $key = $key ?? self::generateRandomKey();
        $instance = new static($key);

        return $instance;
    }

    /**
     * Quick one-off response without chat history
     *
     * @param  string  $message  The message to process
     * @return string|array|\LarAgent\Core\Contracts\DataModel The agent's response
     */
    public static function ask(string $message): string|array|\LarAgent\Core\Contracts\DataModel
    {
        return static::make()->respond($message);
    }

    /**
     * Generate a random key for the agent instance
     *
     * @return string The generated random key
     */
    protected static function generateRandomKey(): string
    {
        return Str::random(10);
    }

    /**
     * Process a message and get the agent's response
     *
     * @param  string|null  $message  Optional message to process
     * @return string|array|\LarAgent\Core\Contracts\DataModel|MessageInterface The agent's response
     */
    public function respond(?string $message = null): string|array|\LarAgent\Core\Contracts\DataModel|MessageInterface
    {
        if ($message) {
            $this->message($message);
        }

        $this->setupBeforeRespond();

        $this->callEvent('onConversationStart');

        $message = $this->prepareMessage();

        $this->prepareAgent($message);

        $lastException = null;

        // Try current provider and fallback to next providers on failure
        do {
            try {
                if ($this->returnMessage) {
                    $this->agent->setReturnMessage(true);
                }
                $response = $this->agent->run();

                // Success - proceed with response handling
                $this->callEvent('onConversationEnd', [$response]);

                if ($this->returnMessage) {
                    return $response;
                }

                if ($response instanceof ToolCallMessage) {
                    return $response->toArrayWithMeta();
                }

                if (is_array($response)) {
                    return $this->processArrayResponse($response);
                }

                return (string) $response;
            } catch (\Throwable $th) {
                $this->callEvent('onEngineError', [$th]);
                $lastException = $th;

                // Try to switch to next provider
                if ($this->switchToNextProvider()) {
                    // Re-setup agent with new provider
                    $this->setupBeforeRespond();
                    $this->prepareAgent($message);

                    continue;
                }

                // No more providers to try, throw the last exception
                throw $lastException;
            }
        } while (true);
    }

    /**
     * @deprecated Use array-based provider configuration instead.
     * This method is kept for backward compatibility.
     */
    protected function changeProvider(string $provider)
    {
        $this->provider = $provider;
        // Re-resolve provider list with new single provider
        $this->providerList = $this->resolveProviderList();
        $this->currentProviderIndex = 0;
        $this->applyCurrentProvider();
    }

    /**
     * Process a message and get the agent's response as a stream
     *
     * @param  string|null  $message  Optional message to process
     * @param  callable|null  $callback  Optional callback to process each chunk
     * @return \Generator A stream of response chunks
     */
    public function respondStreamed(?string $message = null, ?callable $callback = null): \Generator
    {
        if ($message) {
            $this->message($message);
        }

        $this->setupBeforeRespond();

        $this->callEvent('onConversationStart');

        $message = $this->prepareMessage();

        $this->prepareAgent($message);

        $instance = $this;

        $generator = (function () use ($instance, $message, $callback) {
            $lastException = null;
            $hasYieldedChunks = false;

            do {
                try {
                    // Run the agent with streaming enabled
                    if ($instance->returnMessage) {
                        $instance->agent->setReturnMessage(true);
                    }
                    $stream = $instance->agent->runStreamed(function ($streamedMessage) use ($callback, $instance) {
                        if ($streamedMessage instanceof StreamedAssistantMessage) {
                            // Call onConversationEnd when the stream message is complete
                            if ($streamedMessage->isComplete()) {
                                $instance->callEvent('onConversationEnd', [$streamedMessage]);
                            }
                        }

                        // Run callback if defined
                        if ($callback) {
                            $callback($streamedMessage);
                        }
                    });

                    foreach ($stream as $chunk) {
                        $hasYieldedChunks = true;
                        yield $chunk;
                    }

                    // Success - exit the loop
                    return;
                } catch (\Throwable $th) {
                    $instance->callEvent('onEngineError', [$th]);
                    $lastException = $th;

                    // Only attempt fallback if no chunks have been yielded yet
                    // Once streaming starts, fallback would corrupt the response
                    if (! $hasYieldedChunks && $instance->switchToNextProvider()) {
                        // Re-setup agent with new provider
                        $instance->setupBeforeRespond();
                        $instance->prepareAgent($message);

                        continue;
                    }

                    // No more providers to try or streaming already started, throw the exception
                    throw $lastException;
                }
            } while (true);
        })();

        return $generator;
    }

    /**
     * Process a message and get the agent's response as a streamable response
     * for Laravel applications
     *
     * @param  string|null  $message  Optional message to process
     * @param  string  $format  Response format: 'plain', 'json', or 'sse'
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamResponse(?string $message = null, string $format = 'plain')
    {
        $contentType = match ($format) {
            'json' => 'application/json',
            'sse' => 'text/event-stream',
            default => 'text/plain',
        };

        return response()->stream(function () use ($message, $format) {
            $accumulated = '';
            $stream = $this->respondStreamed($message, function ($chunk) use (&$accumulated, $format) {
                if ($chunk instanceof \LarAgent\Messages\StreamedAssistantMessage) {
                    $delta = $chunk->getLastChunk();
                    $accumulated .= $delta;

                    if ($format === 'plain') {
                        echo $delta;
                    } elseif ($format === 'json') {
                        echo json_encode([
                            'delta' => $delta,
                            'content' => $chunk->getContent(),
                            'complete' => $chunk->isComplete(),
                        ])."\n";
                    } elseif ($format === 'sse') {
                        echo "event: chunk\n";
                        echo 'data: '.json_encode([
                            'delta' => $delta,
                            'content' => $chunk->getContent(),
                            'complete' => $chunk->isComplete(),
                        ])."\n\n";
                    }

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                } elseif (is_array($chunk)) {
                    // Handle structured output (JSON schema response)
                    if ($format === 'plain') {
                        echo json_encode($chunk, JSON_PRETTY_PRINT);
                    } elseif ($format === 'json') {
                        echo json_encode([
                            'type' => 'structured',
                            'delta' => '',
                            'content' => $chunk,
                            'complete' => true,
                        ])."\n";
                    } elseif ($format === 'sse') {
                        echo "event: structured\n";
                        echo 'data: '.json_encode([
                            'type' => 'structured',
                            'delta' => '',
                            'content' => $chunk,
                            'complete' => true,
                        ])."\n\n";
                    }

                    ob_flush();
                    flush();
                }
            });

            // Consume the stream
            foreach ($stream as $_) {
                // The callback handles the output
            }

            // Signal completion
            if ($format === 'sse') {
                echo "event: complete\n";
                echo 'data: '.json_encode(['content' => $accumulated])."\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // Overridables

    /**
     * Get the instructions for the agent
     *
     * @return string The agent's instructions
     */
    public function instructions()
    {
        return $this->instructions;
    }

    /**
     * Get the model for the agent
     *
     * @return string The agent's model
     */
    public function model()
    {
        return $this->model;
    }

    /**
     * Dynamically set the API Key for the driver
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * Dynamically set the API URL for the driver
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    /**
     * Process a message before sending to the agent
     *
     * @param  string  $message  The message to process
     * @return string The processed message
     */
    public function prompt(string $message)
    {
        return $message;
    }

    /**
     * Get the structured output schema if any
     *
     * @return array|null The response schema or null if none set
     */
    public function structuredOutput()
    {
        if (empty($this->responseSchema)) {
            return null;
        }

        // If it's a DataModel instance, call toSchema()
        if ($this->responseSchema instanceof \LarAgent\Core\Contracts\DataModel) {
            return $this->responseSchema->toSchema();
        }

        // If it's a DataModel class name, call generateSchema() statically
        if (is_string($this->responseSchema) && is_subclass_of($this->responseSchema, \LarAgent\Core\Contracts\DataModel::class)) {
            return $this->responseSchema::generateSchema();
        }

        // Otherwise, return the array schema as-is if it's an array, otherwise return null
        return is_array($this->responseSchema) ? $this->responseSchema : null;
    }

    /**
     * Get the DataModel class name if responseSchema is a DataModel
     *
     * Override this method when using structuredOutput() with a custom array schema
     * but still want DataModel reconstruction.
     *
     * @return string|null The DataModel class name or null if not applicable
     */
    public function getResponseSchemaClass(): ?string
    {
        if ($this->responseSchema instanceof \LarAgent\Core\Contracts\DataModel) {
            return get_class($this->responseSchema);
        }

        if (is_string($this->responseSchema) && is_subclass_of($this->responseSchema, \LarAgent\Core\Contracts\DataModel::class)) {
            return $this->responseSchema;
        }

        return null;
    }

    /**
     * Reconstruct a DataModel instance from array response
     *
     * @param  array  $response  The array response from LLM
     * @param  string  $class  The DataModel class name
     * @return \LarAgent\Core\Contracts\DataModel The reconstructed DataModel instance
     */
    protected function reconstructDataModel(array $response, string $class): \LarAgent\Core\Contracts\DataModel
    {
        return $class::fromArray($response);
    }

    /**
     * Process array response and reconstruct DataModel if applicable
     *
     * @param  array  $response  The array response from LLM
     * @return array|\LarAgent\Core\Contracts\DataModel The processed response
     */
    protected function processArrayResponse(array $response): array|\LarAgent\Core\Contracts\DataModel
    {
        $class = $this->getResponseSchemaClass();

        if ($class !== null) {
            return $this->reconstructDataModel($response, $class);
        }

        return $response;
    }

    /**
     * Get the name of the agent
     *
     * @return string The agent's name
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * Register additional tools for the agent
     *
     * Override this method in child classes to register custom tools.
     * Tools should be instances of LarAgent\Tool class.
     *
     * Example:
     * ```php
     * public function registerTools() {
     *     return [
     *         Tool::create("user_location", "Returns user's current location")
     *              ->setCallback(function () use ($user) {
     *                   return $user->location()->city;
     *              }),
     *         Tool::create("get_current_weather", "Returns the current weather in a given location")
     *              ->addProperty("location", "string", "The city and state, e.g. San Francisco, CA")
     *              ->setCallback("getWeather"),
     *     ];
     * }
     * ```
     *
     * @return array Array of Tool instances
     */
    public function registerTools()
    {
        return [];
    }

    /**
     * Register MCP servers for the agent
     *
     * Override this method in child classes to register custom MCP servers.
     * MCP servers should be instances of LarAgent\Mcp class.
     *
     * Example:
     * ```php
     * public function registerMcpServers() {
     *     return [
     *         "github_mcp",
     *          "server_name:tools",
     *          "server_name_2:resources",
     *          "server_name_3:tools|except:remove_image,resize_image",
     *          "server_name_3:tools|only:get_image",
     *     ];
     * }
     * ```
     *
     * @return array Array of MCP server names and/or facade instances
     */
    public function registerMcpServers()
    {
        return [];
    }

    /**
     * Parse MCP server configuration string into components
     *
     * Handles various formats:
     * - "server_name" -> ['serverName' => 'server_name', 'method' => null, 'filter' => null, 'filterArguments' => []]
     * - "server_name:tools" -> ['serverName' => 'server_name', 'method' => 'tools', 'filter' => null, 'filterArguments' => []]
     * - "server_name:tools|except:arg1,arg2" -> ['serverName' => 'server_name', 'method' => 'tools', 'filter' => 'except', 'filterArguments' => ['arg1', 'arg2']]
     * - "server_name:tools|only:arg1" -> ['serverName' => 'server_name', 'method' => 'tools', 'filter' => 'only', 'filterArguments' => ['arg1']]
     *
     * @param  string  $serverConfig  The MCP server configuration string
     * @return array Parsed components with keys: serverName, method, filter, filterArguments
     */
    protected function parseMcpServerConfig(string $serverConfig): array
    {
        $result = [
            'serverName' => null,
            'method' => null,
            'filter' => null,
            'filterArguments' => [],
        ];

        // Split by pipe to separate server:method from filter
        $parts = explode('|', $serverConfig, 2);
        $serverPart = trim($parts[0]);
        $filterPart = isset($parts[1]) ? trim($parts[1]) : null;

        // Parse server:method part
        if (str_contains($serverPart, ':')) {
            [$serverName, $method] = explode(':', $serverPart, 2);
            $result['serverName'] = trim($serverName);
            $result['method'] = trim($method);
        } else {
            $result['serverName'] = $serverPart;
        }

        // Parse filter part if present
        if ($filterPart) {
            if (str_contains($filterPart, ':')) {
                [$filter, $arguments] = explode(':', $filterPart, 2);
                $result['filter'] = trim($filter);

                // Parse comma-separated arguments
                if (! empty(trim($arguments))) {
                    $result['filterArguments'] = array_map('trim', explode(',', $arguments));
                }
            } else {
                $result['filter'] = $filterPart;
            }
        }

        return $result;
    }

    protected function buildToolsFromMcpServers()
    {
        $tools = [];
        foreach ($this->getMcpServers() as $serverConfig) {
            $parsedConfig = $this->parseMcpServerConfig($serverConfig);

            // Try to get from cache first
            if ($this->toolCaching) {
                $cachedTools = $this->getToolsFromCache($parsedConfig);
                if ($cachedTools !== null) {
                    $tools = array_merge($tools, $cachedTools);

                    continue;
                }
            }

            $toolInstances = $this->buildToolsFromMcpConfig($parsedConfig);

            // Cache the results
            if ($this->toolCaching && ! empty($toolInstances)) {
                $this->cacheTools($parsedConfig, $toolInstances);
            }

            $tools = array_merge($tools, $toolInstances ?? []);
        }

        return $tools;
    }

    /**
     * Initialize tool caching configuration from config
     */
    protected function setupToolCaching()
    {
        $config = config('laragent.mcp_tool_caching', []);
        $this->toolCaching = $config['enabled'] ?? false;
        $this->toolCacheTtl = $config['ttl'] ?? 3600;
        $this->toolCacheStore = $config['store'] ?? null;
    }

    /**
     * Generate cache key for MCP tool configuration
     *
     * Note: Cache is shared across all users and agents for the same server configuration.
     * If MCP tools return different results based on user context or permissions,
     * consider implementing per-user or per-agent cache scoping.
     */
    protected function getCacheKey(array $parsedConfig): string
    {
        // Create a unique key based on server name, method, filter, and arguments
        $key = 'laragent:tools:'.$parsedConfig['serverName'];
        if ($parsedConfig['method']) {
            $key .= ':'.$parsedConfig['method'];
        }
        if ($parsedConfig['filter']) {
            $key .= ':'.$parsedConfig['filter'];
        }
        if (! empty($parsedConfig['filterArguments'])) {
            $key .= ':'.md5(json_encode($parsedConfig['filterArguments']));
        }

        return $key;
    }

    /**
     * Retrieve cached tool definitions and reconstruct Tool instances
     */
    protected function getToolsFromCache(array $parsedConfig): ?array
    {
        $key = $this->getCacheKey($parsedConfig);
        $store = \Illuminate\Support\Facades\Cache::store($this->toolCacheStore);

        if ($store->has($key)) {
            $cachedData = $store->get($key);

            return $this->reconstructTools($cachedData, $parsedConfig['serverName']);
        }

        return null;
    }

    /**
     * Serialize and cache tool definitions with configured TTL
     */
    protected function cacheTools(array $parsedConfig, array $tools): void
    {
        $key = $this->getCacheKey($parsedConfig);
        $store = \Illuminate\Support\Facades\Cache::store($this->toolCacheStore);

        $toolsData = array_map(function ($tool) {
            return [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'properties' => $tool->getProperties(),
                'required' => $tool->getRequired(),
            ];
        }, $tools);

        $store->put($key, $toolsData, $this->toolCacheTtl);
    }

    /**
     * Reconstruct Tool instances from cached data
     */
    protected function reconstructTools(array $toolsData, string $serverName): array
    {
        $tools = [];
        foreach ($toolsData as $data) {
            $tool = new Tool($data['name'], $data['description']);
            $tool->setProperties($data['properties']);
            $tool->setRequiredProps($data['required']);

            $instance = $this;
            $toolName = $data['name'];

            if (! isset($this->mcpConnections[$serverName])) {
                try {
                    $this->mcpConnections[$serverName] = $this->createMcpClient()->connect($serverName);
                } catch (\Exception $e) {
                    throw new \RuntimeException("Failed to connect to MCP server '{$serverName}'", 0, $e);
                }
            }

            $tool->setCallback(function (...$args) use ($instance, $toolName, $serverName) {
                return json_encode($instance->mcpConnections[$serverName]->callTool($toolName, $args));
            });

            $tools[] = $tool;
        }

        return $tools;
    }

    protected function buildToolsFromMcpConfig(array $mcpConfig): ?array
    {
        // @todo Implement MCP-related events
        if (! isset($mcpConfig['serverName'])) {
            return null;
        }

        $serverName = $mcpConfig['serverName'];
        $client = $this->createMcpClient()->connect($serverName);
        $this->mcpConnections[$serverName] = $client;

        $resourcesCollection = [];
        $toolCollection = [];

        // @todo move as sepearate method
        if ($mcpConfig['method'] === 'tools') {
            // Fetch tools from MCP server
            if (isset($mcpConfig['filter']) && isset($mcpConfig['filterArguments'])) {
                $filter = $mcpConfig['filter'];
                $filterArguments = $mcpConfig['filterArguments'];

                $toolCollection = $client->tools()->{$filter}($filterArguments);
            } else {
                $toolCollection = $client->tools();
            }
        } elseif ($mcpConfig['method'] === 'resources') {
            // Fetch resources from MCP server
            if (isset($mcpConfig['filter']) && isset($mcpConfig['filterArguments'])) {
                $filter = $mcpConfig['filter'];
                $filterArguments = $mcpConfig['filterArguments'];

                $resourcesCollection = $client->resources()->{$filter}($filterArguments);
            } else {
                $resourcesCollection = $client->resources();
            }
        } else {
            // Resources are fetched only if explicitly requested
            try {
                // Default to fetching tools if no method specified
                $toolCollection = $client->tools();
            } catch (\Exception $e) {
                return null;
            }
        }

        $toolsFromCollection = [];
        // @todo move as sepearate method
        // Process tool collection
        if (! empty($toolCollection)) {
            // Loop over each tool in the collection
            // And create Tool instances
            // dd($toolCollection);
            foreach ($toolCollection as $mcpTool) {
                $toolName = $mcpTool['name'] ?? null;
                $toolDesc = $mcpTool['description'] ?? null;
                if (! $toolName || ! $toolDesc) {
                    continue;
                }
                $tool = new Tool(
                    $toolName,
                    $toolDesc
                );

                // Add input schema as tool properties
                $properties = $mcpTool['inputSchema']['properties'] ?? [];
                $required = $mcpTool['inputSchema']['required'] ?? [];

                // Dirty way to set required properties, trusting MCP server input schema
                $tool->setProperties($properties);
                $tool->setRequiredProps($required);

                // Bind the method to the tool, handling both static and instance methods
                $instance = $this;
                $tool->setCallback(function (...$args) use ($instance, $toolName, $serverName) {
                    return json_encode($instance->mcpConnections[$serverName]->callTool($toolName, $args));
                });
                $toolsFromCollection[] = $tool;
            }
        }

        // @todo move as sepearate method
        // Process resource collection
        $resourcesFromCollection = [];
        if (! empty($resourcesCollection)) {
            // Loop over each resource in the collection
            // And create Resource instances
            foreach ($resourcesCollection as $mcpResource) {
                $resourceName = $mcpResource['name'] ?? null;
                $resourceUri = $mcpResource['uri'] ?? null;
                $desc = 'Read the resource';
                $desc .= isset($mcpResource['description']) ? ': '.$mcpResource['description'] : '';
                if (! $resourceName || ! $resourceUri) {
                    continue;
                }

                $tool = Tool::create(
                    Str::snake($resourceName),
                    $desc
                );

                // Bind the method to the tool, handling both static and instance methods
                $instance = $this;
                $tool->setCallback(function () use ($instance, $serverName, $resourceUri) {
                    return json_encode($instance->mcpConnections[$serverName]->readResource($resourceUri));
                });
                $resourcesFromCollection[] = $tool;
            }
        }

        return array_merge($toolsFromCollection, $resourcesFromCollection);
    }

    protected function initMcpClient()
    {
        $this->mcpClient = $this->createMcpClient();
    }

    protected function createMcpClient(): MCPClient
    {
        $servers = config('laragent.mcp_servers') ?? [];

        return new MCPClient($servers);
    }

    // Public accessors / mutators

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getTools(): array
    {
        // Get tools from $tools property (class names)
        $classTools = array_map(function ($tool) {
            if (is_string($tool) && class_exists($tool)) {
                return new $tool;
            }

            return $tool;
        }, $this->tools);

        // Get tools from registerTools method (instances)
        $registeredTools = $this->registerTools();

        $attributeTools = $this->buildToolsFromAttributeMethods();

        $mcpTools = $this->buildToolsFromMcpServers();

        // Merge both arrays
        return array_merge($classTools, $registeredTools, $attributeTools, $mcpTools);
    }

    /**
     * Get MCP servers registered for this agent
     */
    public function getMcpServers(): array
    {
        // Merge both arrays, remove duplicates
        $allMcpServers = array_unique(array_merge($this->mcpServers, $this->registerMcpServers()));

        return $allMcpServers;
    }

    public function chatHistory(): ChatHistoryInterface
    {
        return $this->context()->getStorage(ChatHistoryStorage::class);
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): static
    {
        $this->context()->register($chatHistory);

        return $this;
    }

    protected function setupChatHistory(): void
    {
        $this->setChatHistory($this->createChatHistory());
    }

    /**
     * Create a new chat history instance
     *
     * @return ChatHistoryInterface The created chat history instance
     */
    public function createChatHistory()
    {
        $historyStorageDrivers = $this->historyStorageDrivers();

        $ChatHistoryStorage = new ChatHistoryStorage(
            $this->context()->getIdentity(),
            $historyStorageDrivers,
            $this->storeMeta ?? false
        );

        return $ChatHistoryStorage;
    }

    /**
     * Save the context manually.
     * Useful for explicitly saving mid-request.
     * Events are dispatched safely (skipped if app is shutting down).
     */
    public function saveContext(): static
    {
        $this->context()->save();

        return $this;
    }

    public function readContext(): static
    {
        $this->context()->read();

        return $this;
    }

    protected function historyStorageDrivers(): string|array
    {
        if (is_string($this->history)) {
            return $this->builtInHistories[$this->history] ?? $this->history;
        }
        if (! isset($this->history)) {
            return $this->defaultStorageDrivers();
        }

        return $this->history;
    }

    // ========== Usage Storage Methods ==========

    /**
     * Check if usage tracking is enabled.
     * Priority: Agent property > Provider config > Global config
     */
    public function shouldTrackUsage(): bool
    {
        // Check agent property first (if explicitly set to true/false)
        if ($this->trackUsage !== null) {
            return $this->trackUsage;
        }

        // Check provider-specific config
        $currentProviderName = $this->getCurrentProviderName();
        $providerConfig = config("laragent.providers.{$currentProviderName}.track_usage");
        if ($providerConfig !== null) {
            return (bool) $providerConfig;
        }

        // Fall back to global config
        return config('laragent.track_usage', false);
    }

    /**
     * Enable or disable usage tracking.
     */
    public function trackUsage(bool $enabled = true): static
    {
        $this->trackUsage = $enabled;

        // Setup storage if enabling tracking after construction
        if ($enabled && ! $this->context()->has(\LarAgent\Usage\UsageStorage::class)) {
            $this->setupUsageStorage();
        }

        return $this;
    }

    /**
     * Get the usage storage instance.
     *
     * @return \LarAgent\Usage\UsageStorage|null Returns null if tracking is disabled
     */
    public function usageStorage(): ?\LarAgent\Usage\UsageStorage
    {
        if (! $this->shouldTrackUsage()) {
            return null;
        }

        return $this->context()->getStorage(\LarAgent\Usage\UsageStorage::class);
    }

    /**
     * Set usage storage instance.
     */
    public function setUsageStorage(\LarAgent\Usage\UsageStorage $usageStorage): static
    {
        $this->context()->register($usageStorage);

        return $this;
    }

    /**
     * Setup usage storage.
     */
    protected function setupUsageStorage(): void
    {
        $this->setUsageStorage($this->createUsageStorage());
    }

    /**
     * Create a new usage storage instance.
     * Can be overridden in child classes for custom behavior.
     */
    public function createUsageStorage(): \LarAgent\Usage\UsageStorage
    {
        $usageStorageDrivers = $this->usageStorageDrivers();

        return new \LarAgent\Usage\UsageStorage(
            $this->context()->getIdentity(),
            $usageStorageDrivers,
            $this->model(),
            $this->providerName
        );
    }

    /**
     * Get usage storage drivers configuration.
     * Priority: agent property > provider config > default_usage_storage config > defaultStorageDrivers
     * Note: Provider and global config are resolved in setupProviderData()
     */
    protected function usageStorageDrivers(): string|array
    {
        if (is_string($this->usageStorage)) {
            return $this->builtInUsageStorages[$this->usageStorage] ?? $this->usageStorage;
        }
        if (isset($this->usageStorage) && is_array($this->usageStorage)) {
            return $this->usageStorage;
        }

        return $this->defaultStorageDrivers();
    }

    /**
     * Track usage from a message response.
     * Called automatically in afterResponse hook.
     */
    protected function trackUsageFromMessage(\LarAgent\Core\Contracts\Message $message): void
    {
        if (! $this->shouldTrackUsage()) {
            return;
        }

        $storage = $this->usageStorage();
        if ($storage === null) {
            return;
        }

        // Update model and provider name in case they changed
        $storage->setModelName($this->model());
        $storage->setProviderName($this->providerName);

        // Check if message has usage data
        if (method_exists($message, 'getUsage')) {
            $usage = $message->getUsage();
            if ($usage !== null) {
                $storage->addUsage($usage);
            }
        }
    }

    /**
     * Get usage records filtered by criteria.
     *
     * @param  array  $filters  Optional filters (agent_name, user_id, model_name, provider_name, date, etc.)
     */
    public function getUsage(array $filters = []): ?\LarAgent\Usage\DataModels\UsageArray
    {
        $storage = $this->usageStorage();
        if ($storage === null) {
            return null;
        }

        return $storage->getFilteredUsage($filters);
    }

    /**
     * Get aggregated usage statistics.
     *
     * @param  array  $filters  Optional filters
     */
    public function getUsageAggregate(array $filters = []): ?array
    {
        $storage = $this->usageStorage();
        if ($storage === null) {
            return null;
        }

        return $storage->aggregate($filters);
    }

    /**
     * Get usage grouped by a field.
     *
     * @param  string  $field  Field to group by (agent_name, user_id, model_name, provider_name)
     * @param  array  $filters  Optional filters
     */
    public function getUsageGroupedBy(string $field, array $filters = []): ?array
    {
        $storage = $this->usageStorage();
        if ($storage === null) {
            return null;
        }

        return $storage->groupBy($field, $filters);
    }

    /**
     * Get usage identities tracked for this agent class.
     */
    public function getUsageIdentities(): \LarAgent\Context\DataModels\SessionIdentityArray
    {
        return $this->context()->getTrackedIdentitiesByScope(\LarAgent\Usage\UsageStorage::getStoragePrefix());
    }

    /**
     * Clear all usage records for this identity.
     */
    public function clearUsage(): static
    {
        $storage = $this->usageStorage();
        if ($storage !== null) {
            $storage->clear();
            $storage->save();
        }

        return $this;
    }

    // ========== End Usage Storage Methods ==========

    // ========== Truncation Methods ==========

    /**
     * Check if truncation is enabled.
     * Priority: Agent property > Provider config > Global config
     */
    public function shouldTruncate(): bool
    {
        if ($this->enableTruncation !== null) {
            return $this->enableTruncation;
        }

        $currentProviderName = $this->getCurrentProviderName();
        $providerConfig = config("laragent.providers.{$currentProviderName}.enable_truncation");
        if ($providerConfig !== null) {
            return (bool) $providerConfig;
        }

        return config('laragent.enable_truncation', false);
    }

    /**
     * Enable or disable truncation.
     */
    public function enableTruncation(bool $enabled = true): static
    {
        $this->enableTruncation = $enabled;
        if ($enabled) {
            $this->setupTruncation();
        }

        return $this;
    }

    /**
     * Get the truncation strategy for this agent.
     * Override this method to provide custom strategy configuration.
     * Uses config values as defaults when not overridden.
     */
    protected function truncationStrategy(): ?\LarAgent\Context\Contracts\TruncationStrategy
    {
        $strategyClass = config('laragent.default_truncation_strategy', \LarAgent\Context\Truncation\SimpleTruncationStrategy::class);
        $strategyConfig = config('laragent.default_truncation_config', [
            'keep_messages' => 10,
            'preserve_system' => true,
        ]);

        return new $strategyClass($strategyConfig);
    }

    /**
     * Setup truncation on context.
     */
    protected function setupTruncation(): void
    {
        if (! $this->shouldTruncate()) {
            return;
        }

        $strategy = $this->truncationStrategy();
        $threshold = $this->getTruncationThreshold();
        $buffer = config('laragent.truncation_buffer', 0.2);

        $this->context()->setTruncationStrategy($strategy);
        $this->context()->setTruncationThreshold($threshold);
        $this->context()->setTruncationBuffer($buffer);
    }

    /**
     * Get truncation threshold (in tokens).
     * This is the token count at which truncation strategies are applied.
     * Priority: Agent property > Provider config > Default (128000)
     */
    public function getTruncationThreshold(): int
    {
        if ($this->truncationThreshold !== null) {
            return $this->truncationThreshold;
        }

        $currentProviderName = $this->getCurrentProviderName();

        return config(
            "laragent.providers.{$currentProviderName}.default_truncation_threshold",
            128000
        );
    }

    /**
     * Apply truncation to chat history if needed.
     * Called before sending messages to the LLM.
     */
    protected function applyTruncationIfNeeded(): void
    {
        if (! $this->shouldTruncate()) {
            return;
        }

        // Get total tokens from the last message with usage data
        // total_tokens represents the cumulative token count of the entire conversation
        $currentTokens = $this->getLastKnownTotalTokens();

        // Apply truncation via context
        $this->context()->applyTruncation($this->chatHistory(), $currentTokens);
    }

    /**
     * Get total tokens from the last message that has usage data.
     * Searches messages in reverse order to find the most recent usage information.
     *
     * @return int Total tokens from last message with usage, or 0 if none found
     */
    protected function getLastKnownTotalTokens(): int
    {
        $messages = $this->chatHistory()->getMessages()->all();

        // Search from the end to find the last message with usage data
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if (method_exists($message, 'getUsage')) {
                $usage = $message->getUsage();
                if ($usage !== null && isset($usage->totalTokens)) {
                    return $usage->totalTokens;
                }
            }
        }

        // Log warning when truncation is enabled but no usage data is available
        if (count($messages) > 0) {
            $this->logWarning(
                'LarAgent: Truncation is enabled but no messages have usage data. '
                .'Truncation will not trigger until usage data is available. '
                .'Ensure your LLM driver provides usage information in responses.',
                ['agent' => static::class, 'message_count' => count($messages)]
            );
        }

        return 0;
    }

    // ========== End Truncation Methods ==========

    protected function defaultStorageDrivers(): array
    {
        if (! isset($this->storage)) {
            // Ultimate fallback to InMemoryStorage
            return [\LarAgent\Context\Drivers\InMemoryStorage::class];
        }

        return $this->storage;
    }

    /**
     * Configure respond() to return MessageInterface instance.
     */
    public function returnMessage(bool $return = true): static
    {
        $this->returnMessage = $return;

        return $this;
    }

    public function currentMessage(): ?string
    {
        return $this->message;
    }

    public function lastMessage(): ?MessageInterface
    {
        return $this->chatHistory()->getLastMessage();
    }

    public function clear(): static
    {
        $this->callEvent('onClear');
        $this->chatHistory()->clear();
        $this->chatHistory()->writeToMemory();

        return $this;
    }

    /**
     * Get all storage keys associated with this agent class
     *
     * @return array Array of all storage keys tracked by the context
     */
    public function getStorageKeys(): array
    {
        return $this->context()->getTrackedKeys();
    }

    /**
     * Get chat history keys associated with this agent class
     *
     * @return array Array of chat history keys filtered by 'chatHistory' prefix
     */
    public function getChatKeys(): array
    {
        return $this->context()->getTrackedKeysByPrefix(ChatHistoryStorage::getStoragePrefix());
    }

    /**
     * Get chat history identities associated with this agent class
     *
     * @return \LarAgent\Context\DataModels\SessionIdentityArray Array of chat history identities
     */
    public function getChatIdentities(): \LarAgent\Context\DataModels\SessionIdentityArray
    {
        return $this->context()->getTrackedIdentitiesByScope(ChatHistoryStorage::getStoragePrefix());
    }

    public function getModalities(): array
    {
        return $this->modalities;
    }

    public function getAudio(): ?array
    {
        return $this->audio;
    }

    public function withTool(string|ToolInterface $tool): static
    {
        if (is_string($tool) && class_exists($tool)) {
            $tool = new $tool;
        }
        $this->tools[] = $tool;
        $this->callEvent('onToolChange', [$tool, true]);

        return $this;
    }

    public function removeTool(string|ToolInterface $tool): static
    {
        $toolName = $this->getToolName($tool);

        $this->tools = array_filter($this->tools, function ($existingTool) use ($toolName) {
            if ($existingTool->getName() !== $toolName) {
                return true;
            }
            $this->callEvent('onToolChange', [$existingTool, false]);

            return false;
        });

        return $this;
    }

    private function getToolName(string|ToolInterface $tool): string
    {
        if (is_string($tool)) {
            return class_exists($tool) ? (new $tool)->getName() : $tool;
        }

        return $tool->getName();
    }

    public function withImages(array $imageUrls): static
    {
        $this->images = $imageUrls;

        return $this;
    }

    public function withAudios(array $audioStrings): static
    {
        // ['data' => 'base64', 'format' => 'wav']
        // Possible formats: "wav", "mp3", "ogg", "flac", "m4a", "webm"
        $this->audioFiles = $audioStrings;

        return $this;
    }

    // Possible formats: "wav", "mp3", "ogg", "flac", "m4a", "webm"
    public function generateAudio(string $format, string $voice): static
    {
        $this->audio = ['format' => $format, 'voice' => $voice];
        $this->modalities = ['text', 'audio'];

        return $this;
    }

    public function temperature(float $temp): static
    {
        $this->temperature = $temp;

        return $this;
    }

    public function n(int $n): static
    {
        $this->n = $n;

        return $this;
    }

    public function topP(float $topP): static
    {
        $this->topP = $topP;

        return $this;
    }

    public function frequencyPenalty(float $penalty): static
    {
        $this->frequencyPenalty = $penalty;

        return $this;
    }

    public function presencePenalty(float $penalty): static
    {
        $this->presencePenalty = $penalty;

        return $this;
    }

    public function maxCompletionTokens(int $tokens): static
    {
        $this->maxCompletionTokens = $tokens;

        return $this;
    }

    public function parallelToolCalls(?bool $parallel): static
    {
        $this->parallelToolCalls = $parallel;

        return $this;
    }

    public function responseSchema(null|array|string|\LarAgent\Core\Contracts\DataModel $schema): static
    {
        $this->responseSchema = $schema;

        return $this;
    }

    /**
     * Set tool choice to 'auto' - model can choose to use zero, one, or multiple tools.
     * Only applies if tools are registered.
     */
    public function toolAuto(): static
    {
        $this->toolChoice = 'auto';

        return $this;
    }

    /**
     * Set tool choice to 'none' - prevent the model from using any tools.
     * This simulates the behavior of not passing any functions.
     */
    public function toolNone(): static
    {
        $this->toolChoice = 'none';

        return $this;
    }

    /**
     * Set tool choice to 'required' - model must use at least one tool.
     * Only applies if tools are registered.
     */
    public function toolRequired(): static
    {
        $this->toolChoice = 'required';

        return $this;
    }

    /**
     * Force the model to use a specific tool.
     * Only applies if the specified tool is registered.
     */
    public function forceTool(string $toolName): static
    {
        $this->toolChoice = [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
            ],
        ];

        return $this;
    }

    /**
     * Get the current tool choice configuration.
     * Returns null if no tools are registered or tool choice is not set.
     */
    public function getToolChoice()
    {
        if (empty($this->tools) || $this->toolChoice === null) {
            return null;
        }

        if ($this->toolChoice === 'none') {
            return 'none';
        }

        return $this->toolChoice;
    }

    public function withModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function addMessage(MessageInterface $message): static
    {
        $this->chatHistory()->addMessage($message);

        return $this;
    }

    /**
     * Convert Agent to DTO
     * // @todo mention DTO in the documentation as state for events
     */
    public function toDTO(): AgentDTO
    {
        $driverConfigs = $this->buildConfigsFromAgent();

        return new AgentDTO(
            provider: $this->getCurrentProviderName(),
            providerName: $this->providerName,
            message: $this->message,
            tools: array_map(fn (ToolInterface $tool) => $tool->getName(), $this->getTools()),
            instructions: $this->instructions,
            responseSchema: $this->resolveResponseSchema($this->responseSchema),
            configuration: [
                'history' => $this->history,
                'model' => $this->model(),
                'driver' => $this->driver,
            ],
            driverConfig: $driverConfigs,
        );
    }

    /**
     * Resolve responseSchema to array format.
     */
    protected function resolveResponseSchema(null|array|string|DataModel $schema): ?array
    {
        if (empty($schema)) {
            return null;
        }

        // If it's a DataModel instance, call toSchema()
        if ($schema instanceof DataModel) {
            return $schema->toSchema();
        }

        // If it's a DataModel class name, call generateSchema() statically
        if (is_string($schema) && is_subclass_of($schema, DataModel::class)) {
            return $schema::generateSchema();
        }

        // Otherwise, return the array schema as-is
        return is_array($schema) ? $schema : null;
    }

    // Helper methods

    protected function setName(): static
    {
        $this->name = class_basename(static::class);

        return $this;
    }

    protected function getProviderData(): ?array
    {
        // If providerList is already resolved, use current provider from list
        if (! empty($this->providerList) && isset($this->providerList[$this->currentProviderIndex])) {
            return $this->providerList[$this->currentProviderIndex]['config'];
        }

        // For string provider, just return config directly
        if (is_string($this->provider)) {
            return config("laragent.providers.{$this->provider}");
        }

        return null;
    }

    /**
     * Get the current provider name (string identifier).
     */
    protected function getCurrentProviderName(): string
    {
        if (! empty($this->providerList) && isset($this->providerList[$this->currentProviderIndex])) {
            return $this->providerList[$this->currentProviderIndex]['name'];
        }

        return is_string($this->provider) ? $this->provider : 'default';
    }

    /**
     * Resolve provider configuration into a prioritized list for fallback.
     *
     * Handles:
     * - String provider: 'default'
     * - Array provider: ['default', 'gemini', 'claude']
     * - Array with overrides: ['default', 'gemini' => ['model' => 'custom-model'], 'claude']
     *
     * @return array Array of ['name' => string, 'config' => array]
     *
     * @throws \RuntimeException If no valid providers are found
     */
    protected function resolveProviderList(): array
    {
        $providerList = [];

        // Determine the provider source
        $providerSource = $this->provider;

        // Only use default_providers config if provider is truly not set (null or empty string)
        // This preserves explicit 'default' provider selection
        if ($providerSource === null || $providerSource === '') {
            $defaultProviders = config('laragent.default_providers');
            if (! empty($defaultProviders) && is_array($defaultProviders)) {
                $providerSource = $defaultProviders;
            } else {
                // Fall back to 'default' provider if nothing configured
                $providerSource = 'default';
            }
        }

        // Handle string provider (single provider)
        if (is_string($providerSource)) {
            $config = config("laragent.providers.{$providerSource}");
            if ($config !== null) {
                $providerList[] = ['name' => $providerSource, 'config' => $config];
            }

            // Check for deprecated fallback_provider config
            $fallbackProvider = config('laragent.fallback_provider');
            if ($fallbackProvider && $fallbackProvider !== $providerSource) {
                $fallbackConfig = config("laragent.providers.{$fallbackProvider}");
                if ($fallbackConfig !== null) {
                    $providerList[] = ['name' => $fallbackProvider, 'config' => $fallbackConfig];
                }
            }

            if (empty($providerList)) {
                throw new \RuntimeException(
                    "LarAgent: Provider '{$providerSource}' not found. Please configure it in 'laragent.providers'."
                );
            }

            return $providerList;
        }

        // Handle array provider (multiple providers with potential overrides)
        if (is_array($providerSource)) {
            foreach ($providerSource as $key => $value) {
                // Determine provider name and overrides
                if (is_int($key)) {
                    // Numeric key: value is provider name, no overrides
                    $providerName = $value;
                    $overrides = [];
                } else {
                    // String key: key is provider name, value should be array of overrides
                    $providerName = $key;
                    if (is_array($value)) {
                        $overrides = $value;
                    } else {
                        // Non-array overrides are likely a configuration error
                        $this->logWarning(
                            "LarAgent: Non-array provider override ignored for '{$providerName}'. Expected array, got ".gettype($value).'.',
                            ['provider' => $providerName, 'value' => $value]
                        );
                        $overrides = [];
                    }
                }

                // Get base config from global config
                $baseConfig = config("laragent.providers.{$providerName}");
                if ($baseConfig === null) {
                    continue;
                }

                // Merge overrides (agent array overrides take precedence)
                $mergedConfig = array_merge($baseConfig, $overrides);
                $providerList[] = ['name' => $providerName, 'config' => $mergedConfig];
            }
        }

        if (empty($providerList)) {
            throw new \RuntimeException(
                'LarAgent: No valid providers found. Please configure at least one provider in "laragent.providers" or "laragent.default_providers".'
            );
        }

        return $providerList;
    }

    /**
     * Get the provider fallback sequence for debugging/observability.
     *
     * @return array Array of provider names in fallback order
     */
    public function getProviderSequence(): array
    {
        return array_map(fn ($p) => $p['name'], $this->providerList);
    }

    /**
     * Get the currently active provider name.
     */
    public function getActiveProviderName(): string
    {
        return $this->getCurrentProviderName();
    }

    /**
     * Check if there is a next provider available for fallback.
     */
    protected function hasNextProvider(): bool
    {
        return $this->currentProviderIndex < count($this->providerList) - 1;
    }

    /**
     * Switch to the next provider in the fallback sequence.
     *
     * @return bool True if successfully switched, false if no more providers
     */
    protected function switchToNextProvider(): bool
    {
        if (! $this->hasNextProvider()) {
            return false;
        }

        $this->currentProviderIndex++;
        $this->applyCurrentProvider();

        return true;
    }

    /**
     * Reset provider index to primary (first) provider.
     */
    protected function resetToFirstProvider(): void
    {
        $this->currentProviderIndex = 0;
        if (! empty($this->providerList)) {
            $this->applyCurrentProvider();
        }
    }

    /**
     * Apply the current provider configuration from providerList.
     * Directly overwrites driver-related properties from provider config.
     */
    protected function applyCurrentProvider(): void
    {
        if (empty($this->providerList) || ! isset($this->providerList[$this->currentProviderIndex])) {
            return;
        }

        $current = $this->providerList[$this->currentProviderIndex];
        $providerConfig = $current['config'];

        // Directly apply provider configuration - these properties are always
        // overwritten when switching providers (matching original changeProvider behavior)
        $this->driver = $providerConfig['driver'] ?? config('laragent.default_driver');
        $this->providerName = $providerConfig['label'] ?? $current['name'] ?? '';
        $this->apiKey = $providerConfig['api_key'] ?? null;
        $this->apiUrl = $providerConfig['api_url'] ?? null;
        $this->model = $providerConfig['model'] ?? null;
        $this->maxCompletionTokens = $providerConfig['default_max_completion_tokens'] ?? null;
        $this->truncationThreshold = $providerConfig['default_truncation_threshold'] ?? null;
        $this->storeMeta = $providerConfig['store_meta'] ?? null;
        $this->temperature = $providerConfig['default_temperature'] ?? null;
        $this->n = $providerConfig['default_n'] ?? null;
        $this->topP = $providerConfig['default_top_p'] ?? null;
        $this->frequencyPenalty = $providerConfig['default_frequency_penalty'] ?? null;
        $this->presencePenalty = $providerConfig['default_presence_penalty'] ?? null;
        $this->parallelToolCalls = $providerConfig['parallel_tool_calls'] ?? null;

        // Re-initialize the driver with new config
        $finalConfig = $this->buildConfigsFromAgent();
        $this->initDriver($finalConfig);
    }

    protected function setupDriverConfigs(array $providerData): void
    {
        if (! isset($this->apiKey) && isset($providerData['api_key'])) {
            $this->apiKey = $providerData['api_key'];
        }
        if (! isset($this->apiUrl) && isset($providerData['api_url'])) {
            $this->apiUrl = $providerData['api_url'];
        }

        if (! isset($this->model) && isset($providerData['model'])) {
            $this->model = $providerData['model'];
        }
        if (! isset($this->maxCompletionTokens) && isset($providerData['default_max_completion_tokens'])) {
            $this->maxCompletionTokens = $providerData['default_max_completion_tokens'];
        }
        if (! isset($this->truncationThreshold) && isset($providerData['default_truncation_threshold'])) {
            $this->truncationThreshold = $providerData['default_truncation_threshold'];
        }
        if (! isset($this->storeMeta) && isset($providerData['store_meta'])) {
            $this->storeMeta = $providerData['store_meta'];
        }
        if (! isset($this->temperature) && isset($providerData['default_temperature'])) {
            $this->temperature = $providerData['default_temperature'];
        }
        if (! isset($this->n) && isset($providerData['default_n'])) {
            $this->n = $providerData['default_n'];
        }
        if (! isset($this->topP) && isset($providerData['default_top_p'])) {
            $this->topP = $providerData['default_top_p'];
        }
        if (! isset($this->frequencyPenalty) && isset($providerData['default_frequency_penalty'])) {
            $this->frequencyPenalty = $providerData['default_frequency_penalty'];
        }
        if (! isset($this->presencePenalty) && isset($providerData['default_presence_penalty'])) {
            $this->presencePenalty = $providerData['default_presence_penalty'];
        }
        if (! isset($this->parallelToolCalls) && isset($providerData['parallel_tool_calls'])) {
            $this->parallelToolCalls = $providerData['parallel_tool_calls'];
        }
    }

    protected function initDriver(DriverConfig $config): void
    {
        $this->llmDriver = new $this->driver($config);
    }

    protected function setupProviderData(): void
    {
        // Build the provider list (with fallback sequence)
        $this->providerList = $this->resolveProviderList();
        $this->currentProviderIndex = 0;

        // Get the primary provider config
        $provider = $this->getProviderData();

        if ($provider === null) {
            // If no provider found, use defaults
            $provider = [];
        }

        if (! isset($this->driver)) {
            $this->driver = $provider['driver'] ?? config('laragent.default_driver');
        }
        if (! isset($this->history)) {
            $this->history = $provider['history'] ?? config('laragent.default_history_storage');
        }
        if (! isset($this->usageStorage)) {
            $this->usageStorage = $provider['usage_storage'] ?? config('laragent.default_usage_storage');
        }
        if (! isset($this->storage)) {
            $this->storage = $provider['storage'] ?? config('laragent.default_storage');
        }
        $this->providerName = $provider['label'] ?? $this->getCurrentProviderName() ?? '';

        // Extract provider settings into agent properties
        $this->setupDriverConfigs($provider);

        // Build final config from agent properties (which now include provider defaults)
        $finalConfig = $this->buildConfigsFromAgent();

        $this->initDriver($finalConfig);
    }

    protected function setupAgent(): void
    {
        $config = $this->buildConfigsFromAgent();
        $this->agent = LarAgent::setup($this->llmDriver, $this->chatHistory(), $config);
    }

    /**
     * Build configuration DriverConfig from agent properties.
     * Overrides provider data with agent properties.
     *
     * @return DriverConfig The configuration DTO with model, API key, API URL, and optional parameters.
     */
    protected function buildConfigsFromAgent(): DriverConfig
    {
        $config = new DriverConfig(
            model: $this->model(),
            apiKey: $this->getApiKey(),
            apiUrl: $this->getApiUrl(),
            maxCompletionTokens: $this->maxCompletionTokens ?? null,
            temperature: $this->temperature ?? null,
            n: $this->n ?? null,
            topP: $this->topP ?? null,
            frequencyPenalty: $this->frequencyPenalty ?? null,
            presencePenalty: $this->presencePenalty ?? null,
            parallelToolCalls: $this->parallelToolCalls ?? null,
            toolChoice: $this->toolChoice ?? null,
            modalities: ! empty($this->modalities) ? $this->modalities : null,
            audio: ! empty($this->audio) ? $this->audio : null,
        );

        return $config->withExtra($this->getConfigs());
    }

    protected function registerEvents(): void
    {
        $instance = $this;

        $this->agent->beforeReinjectingInstructions(function ($agent, $chatHistory) use ($instance) {
            $returnValue = $instance->callEvent('beforeReinjectingInstructions', [$chatHistory]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeSend(function ($agent, $history, $message) use ($instance) {
            $returnValue = $instance->callEvent('beforeSend', [$history, $message]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $forceSaveChatHistory = $this->forceSaveHistory;

        $this->agent->afterSend(function ($agent, $history, $message) use ($instance, $forceSaveChatHistory) {
            $returnValue = $instance->callEvent('afterSend', [$history, $message]);

            if ($forceSaveChatHistory) {
                $instance->chatHistory()->save();
            }

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeSaveHistory(function ($agent, $history) use ($instance) {
            $returnValue = $instance->callEvent('beforeSaveHistory', [$history]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeResponse(function ($agent, $history, $message) use ($instance) {
            $returnValue = $instance->callEvent('beforeResponse', [$history, $message]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->afterResponse(function ($agent, $message) use ($instance) {
            // Track usage if enabled and message has usage data
            $instance->trackUsageFromMessage($message);

            $returnValue = $instance->callEvent('afterResponse', [$message]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeToolExecution(function ($agent, $tool, $toolCall) use ($instance) {
            $returnValue = $instance->callEvent('beforeToolExecution', [$tool, $toolCall]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->afterToolExecution(function ($agent, $tool, $toolCall, &$result) use ($instance) {
            $returnValue = $instance->callEvent('afterToolExecution', [$tool, $toolCall, &$result]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeStructuredOutput(function ($agent, &$response) use ($instance) {
            $returnValue = $instance->callEvent('beforeStructuredOutput', [&$response]);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });
    }

    protected function setupBeforeRespond(): void
    {
        $this->setupAgent();
        $this->registerEvents();
    }

    protected function prepareMessage(): MessageInterface
    {
        if ($this->readyMessage) {
            $message = $this->readyMessage;
        } else {
            $message = Message::user($this->prompt($this->message));
        }

        $message->addMeta([
            'agent' => $this->name(),
            'model' => $this->model(),
        ]);

        if (! empty($this->images)) {
            foreach ($this->images as $imageUrl) {
                $message = $message->withImage($imageUrl);
            }
        }

        if (! empty($this->audioFiles)) {
            foreach ($this->audioFiles as $audioFile) {
                $message = $message->withAudio($audioFile['format'], $audioFile['data']);
            }
        }

        return $message;
    }

    protected function prepareAgent(MessageInterface $message): void
    {
        // Apply truncation before preparing agent
        $this->applyTruncationIfNeeded();

        $this->agent
            ->withInstructions($this->instructions(), $this->developerRoleForInstructions)
            ->withMessage($message)
            ->setTools($this->getTools());

        if ($this->structuredOutput()) {
            $this->agent->structured($this->structuredOutput());
        }
    }

    /**
     * Builds tools from methods annotated with #[Tool] attribute
     * Example:
     * ```php
     * #[Tool("Get weather information")]
     * public function getWeather(string $location): array {
     *     return WeatherService::get($location);
     * }
     * ```
     */
    protected function buildToolsFromAttributeMethods(): array
    {
        $tools = [];
        $methods = static::getCachedMethodsWithAttribute(ToolAttribute::class);

        foreach ($methods as $method) {
            $attributes = $method->getAttributes(ToolAttribute::class);

            foreach ($attributes as $attribute) {
                $toolAttribute = $attribute->newInstance();
                $tool = Tool::create(
                    $method->getName(),
                    $toolAttribute->description
                );

                // Add parameters as tool properties using trait methods
                foreach ($method->getParameters() as $param) {
                    $typeInfo = static::getTypeInfo($param->getType());
                    $schema = $typeInfo['schema'];

                    // Extract type and enum values from schema
                    $type = $schema['type'] ?? 'string';
                    $enum = [];

                    if (isset($schema['enum'])) {
                        $enum = [
                            'values' => $schema['enum'],
                            'enumClass' => $typeInfo['enumClass'],
                        ];
                    } elseif (isset($schema['oneOf'])) {
                        // For union types, use the schema as-is
                        $type = $schema;

                        // Store enum classes for union types (can be array of classes)
                        if ($typeInfo['enumClass']) {
                            $tool->addEnumType($param->getName(), $typeInfo['enumClass']);
                        }
                    } elseif ($typeInfo['dataModelClass'] || (($schema['type'] ?? '') === 'object' && isset($schema['properties']))) {
                        // For DataModels/objects with nested properties, use the full schema
                        $type = $schema;
                    }

                    // Store DataModel class if present (can be array for union types)
                    if ($typeInfo['dataModelClass']) {
                        $tool->addDataModelType($param->getName(), $typeInfo['dataModelClass']);
                    }

                    $tool->addProperty(
                        $param->getName(),
                        $type,
                        $toolAttribute->parameterDescriptions[$param->getName()] ?? '',
                        $enum
                    );
                    if (! $param->isOptional()) {
                        $tool->setRequired($param->getName());
                    }
                }

                // Bind the method to the tool, handling both static and instance methods
                $tool->setCallback(
                    $method->isStatic()
                        ? [static::class, $method->getName()]
                        : [$this, $method->getName()]
                );
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
