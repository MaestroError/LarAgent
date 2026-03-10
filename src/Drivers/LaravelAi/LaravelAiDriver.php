<?php

namespace LarAgent\Drivers\LaravelAi;

use Closure;
use Generator;
use LarAgent\Context\Contracts\SessionIdentity as SessionIdentityContract;
use LarAgent\Core\Abstractions\LlmDriver;
use LarAgent\Core\Contracts\HookableDriver;
use LarAgent\Core\Contracts\InterruptableDriver;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\SessionAwareDriver;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\AgentDTO;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Messages\AssistantMessage;

use function Laravel\Ai\agent;

class LaravelAiDriver extends LlmDriver implements HookableDriver, InterruptableDriver, LlmDriverInterface, SessionAwareDriver
{
    protected string $sdkProvider;

    protected ?Closure $beforeToolHook = null;

    protected ?Closure $afterToolHook = null;

    protected bool $interrupted = false;

    /**
     * Session identity for the current request.
     */
    protected ?SessionIdentityContract $sessionIdentity = null;

    /**
     * Conversation store for unified storage with SDK.
     */
    protected ?LarAgentConversationStore $conversationStore = null;

    /**
     * Whether SDK event bridging is enabled.
     */
    protected bool $bridgeEvents;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        if (! ConfigBridge::isSdkAvailable()) {
            throw new \RuntimeException(
                'The laravel/ai package is required to use LaravelAiDriver. '.
                'Install it with: composer require laravel/ai'
            );
        }

        $this->sdkProvider = $this->driverConfig->getExtra('sdk_provider')
            ?? ConfigBridge::toLabEnum($this->driverConfig->getExtra('label', 'openai'));

        $this->bridgeEvents = (bool) $this->driverConfig->getExtra('bridge_events', true);
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        $config = $this->resolveConfig($overrideSettings);
        [$instructions, $promptText, $sdkMessages, $bridgeTools] = $this->prepareSdkCall($messages);

        // If structured output is enabled, append JSON schema to instructions
        $effectiveInstructions = $instructions ?? '';
        if ($this->structuredOutputEnabled()) {
            $effectiveInstructions = $this->appendStructuredOutputInstructions($effectiveInstructions);
        }

        // Register SDK event bridge if enabled
        $this->registerEventBridge();

        // Build prompt arguments
        $promptArgs = $this->buildPromptArgs($config);

        // Call SDK via anonymous agent, wrapping SDK exceptions for consistency
        try {
            $response = agent(
                instructions: $effectiveInstructions,
                messages: $sdkMessages,
                tools: $bridgeTools,
            )->prompt($promptText, ...$promptArgs);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Laravel AI SDK error: '.$e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $this->lastResponse = method_exists($response, 'toArray')
            ? $response->toArray()
            : (array) $response;

        // Convert SDK response to LarAgent AssistantMessage
        $assistantMessage = MessageConverter::fromSdkResponse($response);

        // Extract intermediate tool call/result messages for chat history
        $intermediate = MessageConverter::extractIntermediateMessages($response);
        if (! empty($intermediate)) {
            $assistantMessage->setExtra('intermediate_messages', $intermediate);
        }

        return $assistantMessage;
    }

    public function sendMessageStreamed(array $messages, DriverConfig|array $overrideSettings = [], ?callable $callback = null): Generator
    {
        $config = $this->resolveConfig($overrideSettings);
        [$instructions, $promptText, $sdkMessages, $bridgeTools] = $this->prepareSdkCall($messages);

        // If structured output is enabled, append JSON schema to instructions
        $effectiveInstructions = $instructions ?? '';
        if ($this->structuredOutputEnabled()) {
            $effectiveInstructions = $this->appendStructuredOutputInstructions($effectiveInstructions);
        }

        // Register SDK event bridge if enabled
        $this->registerEventBridge();

        // Build prompt arguments
        $promptArgs = $this->buildPromptArgs($config);

        // Build agent and stream, wrapping SDK exceptions for consistency
        try {
            $sdkAgent = agent(
                instructions: $effectiveInstructions,
                messages: $sdkMessages,
                tools: $bridgeTools,
            );

            $stream = $sdkAgent->stream($promptText, ...$promptArgs);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Laravel AI SDK streaming error: '.$e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $streamedMessage = new \LarAgent\Messages\StreamedAssistantMessage;
        $lastEvent = null;

        foreach ($stream as $event) {
            $lastEvent = $event;

            // Extract text chunks from stream events
            $text = '';
            if (is_object($event) && isset($event->text)) {
                $text = $event->text;
            } elseif (is_string($event)) {
                $text = $event;
            }

            if ($text !== '') {
                $streamedMessage->appendContent($text);

                if ($callback) {
                    $callback($streamedMessage);
                }

                yield $streamedMessage;

                if ($this->interrupted) {
                    return;
                }
            }
        }

        // After stream completes, capture intermediate messages and usage from final event
        if ($lastEvent !== null) {
            if (is_object($lastEvent)) {
                $intermediate = MessageConverter::extractIntermediateMessages($lastEvent);
                if (! empty($intermediate)) {
                    $streamedMessage->setExtra('intermediate_messages', $intermediate);
                }

                $aggregatedUsage = MessageConverter::aggregateStepUsage($lastEvent);
                if ($aggregatedUsage !== null) {
                    $streamedMessage->setUsage($aggregatedUsage);
                }

                $this->lastResponse = method_exists($lastEvent, 'toArray')
                    ? $lastEvent->toArray()
                    : (array) $lastEvent;
            }
        }

        $streamedMessage->setComplete(true);

        if ($callback) {
            $callback($streamedMessage);
        }

        yield $streamedMessage;
    }

    /**
     * Set hook callbacks for tool execution.
     * These are called by SdkToolBridge during the SDK's tool loop.
     */
    public function setHookCallbacks(?Closure $before, ?Closure $after): static
    {
        $this->beforeToolHook = $before;
        $this->afterToolHook = $after;

        return $this;
    }

    /**
     * Set the session identity for the current request.
     */
    public function setSessionIdentity(SessionIdentityContract $identity): static
    {
        $this->sessionIdentity = $identity;

        return $this;
    }

    /**
     * Get the current session identity.
     */
    public function getSessionIdentity(): ?SessionIdentityContract
    {
        return $this->sessionIdentity;
    }

    /**
     * Set the conversation store for unified SDK storage.
     */
    public function setConversationStore(?LarAgentConversationStore $store): static
    {
        $this->conversationStore = $store;

        return $this;
    }

    /**
     * Get the conversation store.
     */
    public function getConversationStore(): ?LarAgentConversationStore
    {
        return $this->conversationStore;
    }

    /**
     * Set the AgentDTO for event bridging context.
     */
    public function setAgentDto(?AgentDTO $agentDto): static
    {
        SdkEventBridge::setAgentDto($agentDto);

        return $this;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function resetInterrupt(): void
    {
        $this->interrupted = false;
    }

    /**
     * Build the prompt arguments array for the SDK agent call.
     * Forwards model parameters (provider, model, temperature, maxTokens, etc.)
     * so the SDK call matches the agent's configuration.
     *
     * @return array Named arguments for the SDK prompt()/stream() call
     */
    protected function buildPromptArgs(DriverConfig $config): array
    {
        return [
            'provider' => $this->sdkProvider,
            'model' => $config->model,
        ];
    }

    /**
     * Append structured output JSON schema instructions to the system prompt.
     * Used when the agent defines $responseSchema but the SDK doesn't have
     * native structured output support.
     */
    protected function appendStructuredOutputInstructions(string $instructions): string
    {
        $schema = $this->getResponseSchema();
        if ($schema === null) {
            return $instructions;
        }

        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $suffix = "\n\nYou MUST respond with valid JSON that matches this schema exactly:\n```json\n{$schemaJson}\n```\nDo not include any other text outside the JSON object.";

        return $instructions.$suffix;
    }

    /**
     * Prepare shared state for SDK calls (used by both sendMessage and sendMessageStreamed).
     *
     * @return array{0: ?string, 1: string, 2: array, 3: array} [$instructions, $promptText, $sdkMessages, $bridgeTools]
     */
    protected function prepareSdkCall(array $messages): array
    {
        // Extract system instructions (returns [?string, array] without mutating input)
        [$instructions, $remaining] = MessageConverter::extractInstructions($messages);

        // Extract the last user message as the prompt text
        $promptText = MessageConverter::extractLastUserMessage($remaining);

        // Convert to SDK messages
        $sdkMessages = MessageConverter::toLaravelAiMessages($remaining);

        // Remove the last SDK user message to avoid duplication with prompt
        if (! empty($sdkMessages) && end($sdkMessages)->role === 'user') {
            array_pop($sdkMessages);
        }

        // Wrap LarAgent tools as SDK tools with hook callbacks
        // Guard each tool name to prevent double-dispatch in SdkEventBridge
        $bridgeTools = SdkToolBridge::fromLarAgentTools(
            $this->tools,
            $this->beforeToolHook,
            $this->afterToolHook
        );

        // Register tool names as guarded for event bridging
        foreach ($this->tools as $tool) {
            SdkEventBridge::guardTool($tool->getName());
        }

        return [$instructions, $promptText, $sdkMessages, $bridgeTools];
    }

    /**
     * Register SDK event bridging if enabled.
     * Resets static state first to prevent stale data leaking across
     * requests (Octane, queues) or multi-provider fallback attempts.
     */
    protected function registerEventBridge(): void
    {
        // Reset stale static state from previous requests
        SdkEventBridge::reset();

        if ($this->bridgeEvents) {
            SdkEventBridge::register();
        }
    }

    /**
     * Resolve the effective config by merging driver config with overrides.
     */
    protected function resolveConfig(DriverConfig|array $overrideSettings): DriverConfig
    {
        $overrideConfig = DriverConfig::wrap($overrideSettings);

        return $this->getDriverConfig()->merge($overrideConfig);
    }

    /**
     * Tool calls are handled internally by the SDK, so these methods
     * return minimal implementations for interface compliance.
     */
    public function toolResultToMessage(ToolCallInterface $toolCall, mixed $result): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCall->getId(),
            'content' => is_string($result) ? $result : json_encode($result),
        ];
    }

    public function toolCallsToMessage(array $toolCalls): array
    {
        $content = [];
        foreach ($toolCalls as $toolCall) {
            $content[] = [
                'id' => $toolCall->getId(),
                'type' => 'function',
                'function' => [
                    'name' => $toolCall->getToolName(),
                    'arguments' => $toolCall->getArguments(),
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'tool_calls' => $content,
        ];
    }
}
