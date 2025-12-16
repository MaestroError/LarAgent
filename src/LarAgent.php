<?php

namespace LarAgent;

use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Core\Traits\Configs;
use LarAgent\Core\Traits\Hooks;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;

class LarAgent
{
    use Configs;
    use Hooks;

    protected DriverConfig $driverConfig;

    protected int $truncationThreshold = 50000;

    protected int $reinjectInstructionsPer = 0; // 0 Means never

    protected bool $useDeveloperForInstructions = false;

    protected string $instructions;

    protected ?MessageInterface $message;

    protected array $responseSchema;

    protected LlmDriverInterface $driver;

    protected ChatHistoryInterface $chatHistory;

    protected array $tools = [];

    /** @var bool Enable streaming mode */
    protected bool $streaming = false;

    /** @var callable|null Callback function for streaming */
    protected $streamCallback = null;

    protected bool $returnMessage = false;

    // Config methods

    public function getModel(): string
    {
        return $this->driverConfig->model ?? 'gpt-4o-mini';
    }

    public function setModel(string $model): self
    {
        $this->driverConfig->set('model', $model);

        return $this;
    }

    public function useModel(string $model): self
    {
        return $this->setModel($model);
    }

    public function getTruncationThreshold(): int
    {
        return $this->truncationThreshold;
    }

    public function setTruncationThreshold(int $truncationThreshold): self
    {
        $this->truncationThreshold = $truncationThreshold;

        return $this;
    }

    public function setReturnMessage(bool $returnMessage = true): self
    {
        $this->returnMessage = $returnMessage;

        return $this;
    }

    public function getMaxCompletionTokens(): ?int
    {
        return $this->driverConfig->maxCompletionTokens;
    }

    public function setMaxCompletionTokens(int $maxCompletionTokens): self
    {
        $this->driverConfig->set('maxCompletionTokens', $maxCompletionTokens);

        return $this;
    }

    public function getTemperature(): ?float
    {
        return $this->driverConfig->temperature;
    }

    public function setTemperature(float $temperature): self
    {
        $this->driverConfig->set('temperature', $temperature);

        return $this;
    }

    public function getN(): ?int
    {
        return $this->driverConfig->n;
    }

    public function setN(?int $n): self
    {
        $this->driverConfig->set('n', $n);

        return $this;
    }

    public function getTopP(): ?float
    {
        return $this->driverConfig->topP;
    }

    public function setTopP(?float $topP): self
    {
        $this->driverConfig->set('topP', $topP);

        return $this;
    }

    public function getFrequencyPenalty(): ?float
    {
        return $this->driverConfig->frequencyPenalty;
    }

    public function setFrequencyPenalty(?float $frequencyPenalty): self
    {
        $this->driverConfig->set('frequencyPenalty', $frequencyPenalty);

        return $this;
    }

    public function getPresencePenalty(): ?float
    {
        return $this->driverConfig->presencePenalty;
    }

    public function setPresencePenalty(?float $presencePenalty): self
    {
        $this->driverConfig->set('presencePenalty', $presencePenalty);

        return $this;
    }

    public function getReinjectInstructionsPer(): int
    {
        return $this->reinjectInstructionsPer;
    }

    public function setReinjectInstructionsPer(int $reinjectInstructionsPer): self
    {
        $this->reinjectInstructionsPer = $reinjectInstructionsPer;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions ?? null;
    }

    public function getUseDeveloperForInstructions(): bool
    {
        return $this->useDeveloperForInstructions;
    }

    public function useDeveloperRole(bool $useDeveloperForInstructions): self
    {
        $this->useDeveloperForInstructions = $useDeveloperForInstructions;

        return $this;
    }

    public function withInstructions(string $instructions, bool $useDeveloperRoleForInstructions = false): self
    {
        $this->instructions = $instructions;
        $this->useDeveloperForInstructions = $useDeveloperRoleForInstructions;

        return $this;
    }

    public function getCurrentMessage(): ?MessageInterface
    {
        return $this->message ?? null;
    }

    public function withMessage(MessageInterface $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getResponseSchema(): ?array
    {
        return $this->responseSchema ?? null;
    }

    public function structured(array $responseSchema): self
    {
        $this->responseSchema = $responseSchema;

        return $this;
    }

    /**
     * Set tool choice to 'auto' - model can choose to use zero, one, or multiple tools
     * Only applies if tools are registered.
     */
    public function toolAuto(): self
    {
        $this->driverConfig->set('toolChoice', 'auto');

        return $this;
    }

    /**
     * Set tool choice to 'none' - prevent the model from using any tools
     * This simulates the behavior of not passing any functions
     */
    public function toolNone(): self
    {
        $this->driverConfig->set('toolChoice', 'none');

        return $this;
    }

    /**
     * Set tool choice to 'required' - model must use at least one tool
     * Only applies if tools are registered.
     */
    public function toolRequired(): self
    {
        $this->driverConfig->set('toolChoice', 'required');

        return $this;
    }

    /**
     * Force the model to use a specific tool
     * Only applies if the specified tool is registered.
     *
     * @param  string  $toolName  Name of the tool to force
     */
    public function forceTool($toolName): self
    {
        $this->driverConfig->set('toolChoice', [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
            ],
        ]);

        return $this;
    }

    /**
     * Get the current tool choice configuration
     * Returns null if no tools are registered or tool choice is not set
     *
     * @return string|array|null Current tool choice setting
     */
    public function getToolChoice()
    {
        // If no tools registered or choice is 'auto' (default), return null
        if (empty($this->tools) || $this->driverConfig->toolChoice === null) {
            return null;
        }

        // If choice is 'none', always return it even without tools
        if ($this->driverConfig->toolChoice === 'none') {
            return 'none';
        }

        // For other choices, only return if tools are registered
        return $this->driverConfig->toolChoice;
    }

    /**
     * Set the tool choice configuration
     *
     * @param  string|array|null  $toolChoice  Tool choice configuration
     * @return $this
     */
    public function setToolChoice(string|array|null $toolChoice): self
    {
        $this->driverConfig->set('toolChoice', $toolChoice);

        return $this;
    }

    /**
     * Enable or disable streaming mode
     *
     * @param  bool  $streaming  Whether to enable streaming
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return $this
     */
    public function streaming(bool $streaming = true, ?callable $callback = null): self
    {
        $this->streaming = $streaming;
        if ($callback !== null) {
            $this->streamCallback = $callback;
        }

        return $this;
    }

    /**
     * Check if streaming is enabled
     */
    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * Get the streaming callback function
     */
    public function getStreamCallback(): ?callable
    {
        return $this->streamCallback;
    }

    public function getParallelToolCalls(): ?bool
    {
        return $this->driverConfig->parallelToolCalls;
    }

    public function setParallelToolCalls(?bool $parallelToolCalls): self
    {
        $this->driverConfig->set('parallelToolCalls', $parallelToolCalls);

        return $this;
    }

    public function getModalities(): ?array
    {
        return $this->driverConfig->modalities;
    }

    public function setModalities(array $modalities): self
    {
        $this->driverConfig->set('modalities', $modalities);

        return $this;
    }

    public function getAudio(): ?array
    {
        return $this->driverConfig->audio;
    }

    public function setAudio(?array $audio): self
    {
        $this->driverConfig->set('audio', $audio);

        return $this;
    }

    // Main API methods

    public function __construct(LlmDriverInterface $driver, ChatHistoryInterface $chatHistory)
    {
        $this->driver = $driver;
        $this->chatHistory = $chatHistory;
        $this->driverConfig = new DriverConfig;
    }

    public static function setup(LlmDriverInterface $driver, ChatHistoryInterface $chatHistory, DriverConfig|array $configs = []): self
    {
        $agent = new self($driver, $chatHistory);
        $agent->initializeConfigs($configs);

        return $agent;
    }

    public function initializeConfigs(DriverConfig|array $configs): void
    {
        // If it's a DriverConfig, merge it directly
        if ($configs instanceof DriverConfig) {
            $this->driverConfig = $this->driverConfig->merge($configs);

            // Handle truncationThreshold from extras if present
            if ($configs->getExtra('truncationThreshold') !== null) {
                $this->truncationThreshold = $configs->getExtra('truncationThreshold');
            }

            // Set any extras from the DriverConfig to Configs trait
            $extras = $configs->getExtras();
            unset($extras['truncationThreshold']); // Already handled
            if (! empty($extras)) {
                $this->setConfigs($extras);
            }

            return;
        }

        // Handle legacy array format (camelCase keys expected)
        // Extract truncationThreshold separately as it's not part of DriverConfig
        if (array_key_exists('truncationThreshold', $configs)) {
            $this->truncationThreshold = $configs['truncationThreshold'];
            unset($configs['truncationThreshold']);
        }

        if (array_key_exists('reinjectInstructionsPer', $configs)) {
            $this->reinjectInstructionsPer = $configs['reinjectInstructionsPer'];
            unset($configs['reinjectInstructionsPer']);
        }

        // Create DriverConfig from remaining array
        $driverConfigFromArray = DriverConfig::fromArray($configs);
        $this->driverConfig = $this->driverConfig->merge($driverConfigFromArray);

        // Set extras from Configs trait (anything not in DriverConfig known keys)
        $extras = $driverConfigFromArray->getExtras();
        if (! empty($extras)) {
            $this->setConfigs($extras);
        }
    }

    public function setTools(array $tools): self
    {
        $this->tools = $tools;

        return $this;
    }

    public function registerTool(array $tools): self
    {
        $this->tools[] = $tools;

        return $this;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    // Execution method
    public function run(): MessageInterface|array|null
    {
        // Prepare the agent for execution
        if ($this->prepareExecution() === false) {
            return null;
        }

        // Use regular mode
        $response = $this->send($this->message);

        // Process the response with common post-processing logic
        return $this->processResponse($response);
    }

    /**
     * Run the agent with streaming enabled.
     *
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return \Generator A generator that yields chunks of the response
     */
    public function runStreamed(?callable $callback = null): \Generator
    {
        // Enable streaming mode if not already enabled
        if (! $this->isStreaming()) {
            $this->streaming(true, $callback);
        }

        // Prepare the agent for execution
        if ($this->prepareExecution() === false) {
            // Return an empty generator when execution is stopped
            return (function () {
                yield from [];
            })();
        }

        // Use streaming mode
        $streamGenerator = $this->stream($this->message, $this->getStreamCallback());

        // Reset message to null to skip adding it again in chat history
        $this->message = null;

        // Return the stream generator
        return $streamGenerator;
    }

    /**
     * Stream a message to the LLM and receive a streamed response.
     *
     * @param  MessageInterface|null  $message  The message to send
     * @param  callable|null  $callback  Optional callback function to process each chunk
     * @return \Generator A generator that yields chunks of the response
     */
    protected function stream(?MessageInterface $message = null, ?callable $callback = null): \Generator
    {
        // Create a user message if provided
        if ($message !== null) {
            $this->chatHistory->addMessage($message);
        }

        // Before response (Before sending message to LLM)
        // If any callback will return false, it will stop the process silently
        if ($this->processBeforeResponse($this->chatHistory, $message) === false) {
            return;
        }

        // Get the streamed response
        $stream = $this->driver->sendMessageStreamed(
            $this->chatHistory->getMessages()->all(),
            $this->buildConfig(),
            $callback
        );

        // Keep track of the final message to add to chat history
        $finalMessage = null;
        $toolCallProcessed = false;

        // Process each chunk of the stream
        foreach ($stream as $chunk) {
            $finalMessage = $chunk;
            yield $chunk;
        }

        // Add the final message to chat history if it exists
        if ($finalMessage) {
            $this->processAfterResponse($finalMessage);
            $this->chatHistory->addMessage($finalMessage);

            // Process the final message with common post-processing logic
            $processedResponse = $this->processResponse($finalMessage);

            // If the response is a generator (from a tool call that triggered another stream),
            // yield its chunks
            if ($processedResponse instanceof \Generator) {
                foreach ($processedResponse as $chunk) {
                    yield $chunk;
                }
            } else {
                yield $processedResponse;
            }
        }
    }

    /**
     * Prepare the agent for execution by handling instructions, tools, and response schema.
     *
     * @return bool False if execution should be stopped, true otherwise
     */
    protected function prepareExecution(): bool
    {
        // Manage instructions
        $totalMessages = $this->chatHistory->count();

        if ($totalMessages === 0 && $this->getInstructions()) {
            $this->injectInstructions();
        } else {
            // Reinject instructions if reinjectInstructionsPer is defined
            $iip = $this->getReinjectInstructionsPer();
            if ($iip && $iip > 0 && $totalMessages % $iip > 0 && $totalMessages % $iip <= 5) {
                // Hook: If any callback returns false, it will stop the process silently
                if ($this->processBeforeReinjectingInstructions($this->chatHistory) === false) {
                    return false;
                }
                $this->injectInstructions();
            }
        }

        // Register tools
        if (! empty($this->tools)) {
            foreach ($this->tools as $tool) {
                $this->driver->registerTool($tool);
            }
        }

        // Set response schema
        if ($this->getResponseSchema()) {
            $this->driver->setResponseSchema($this->responseSchema);
        }

        // Hook: Before send (Before adding message in chat history)
        if ($this->processBeforeSend($this->chatHistory, $this->getCurrentMessage()) === false) {
            return false;
        }

        return true;
    }

    // Helper methods

    protected function send(?MessageInterface $message): ?MessageInterface
    {
        if ($message) {
            $this->chatHistory->addMessage($message);
        }
        // Hook: Before response (Before sending message to LLM)
        // If any callback will return false, it will stop the process silently
        // If you want to rise an exception, you can do it in the callback
        if ($this->processBeforeResponse($this->chatHistory, $message) === false) {
            return null;
        }

        $response = $this->driver->sendMessage($this->chatHistory->getMessages()->all(), $this->buildConfig());
        // After response (After receiving message from LLM)
        $this->processAfterResponse($response);
        $this->chatHistory->addMessage($response);

        // Process the response with common post-processing logic
        return $response;
    }

    /**
     * Process a response message with common post-processing logic.
     *
     * @param  MessageInterface  $response  The response message to process
     * @return MessageInterface|array|null|\Generator The processed response
     */
    protected function processResponse(MessageInterface $response): MessageInterface|array|null|\Generator
    {
        // After send (After adding LLM response to Chat history)
        if ($this->processAfterSend($this->chatHistory, $response) === false) {
            return null;
        }

        // Process tools if the response is a tool call
        if ($response instanceof ToolCallMessage) {

            $this->processTools($response);

            // If tool choice is required or forced some tool
            // Switch to auto tool choice to avoid infinite loop
            if ($this->getToolChoice() !== 'none') {
                $this->toolAuto();
            }

            // If no tool result added, return ToolCallMessage
            if (! ($this->chatHistory->getLastMessage() instanceof ToolResultMessage)) {
                return $response;
            }

            // Continue the conversation with tool results
            if ($this->isStreaming()) {
                return $this->runStreamed();
            }

            // Reset message to null to skip adding it again in chat history
            $this->message = null;

            return $this->run();
        }

        // Hook: Before saving chat history
        $this->processBeforeSaveHistory($this->chatHistory);
        // Save chat history to memory
        $this->chatHistory->writeToMemory();

        if ($this->driver->structuredOutputEnabled()) {

            if ($this->returnMessage) {
                return $response;
            }

            $array = json_decode($response->getContent(), true);
            // Hook: Before structured output response
            if ($this->processBeforeStructuredOutput($array) === false) {
                return null;
            }

            return $array;
        }

        if ($this->getN() !== null && $this->getN() > 1) {
            $decodedContent = json_decode($response->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(
                    'Failed to decode response JSON: '.json_last_error_msg()
                );
            }

            return $decodedContent;
        }

        return $response;
    }

    protected function buildConfig(): DriverConfig
    {
        // Clone the driverConfig and conditionally nullify tool-related settings if no tools
        $config = clone $this->driverConfig;

        if (empty($this->tools)) {
            $config->parallelToolCalls = null;
            $config->toolChoice = null;
        }

        // Add any extra configs from Configs trait
        $extras = $this->getConfigs();
        if (! empty($extras)) {
            $config = $config->withExtra($extras);
        }

        return $config;
    }

    protected function injectInstructions(): void
    {
        if ($this->getUseDeveloperForInstructions()) {
            $message = Message::developer($this->getInstructions());
        } else {
            $message = Message::system($this->getInstructions());
        }
        $this->chatHistory->addMessage($message);
    }

    protected function processTools(ToolCallMessage $message): void
    {
        foreach ($message->getToolCalls() as $toolCall) {
            $result = $this->processToolCall($toolCall);
            if (! $result) {
                continue;
            }
            $this->chatHistory->addMessage($result);
        }
    }

    protected function processToolCall(ToolCallInterface $toolCall): ?ToolResultMessage
    {
        $tool = $this->driver->getTool($toolCall->getToolName());
        $args = json_decode($toolCall->getArguments(), true);
        // Hook: Before tool execution, skip tool if false returned
        if ($this->processBeforeToolExecution($tool, $toolCall) === false) {
            return null;
        }

        // Continue if tool is phantom tool
        if ($tool instanceof PhantomTool) {
            return null;
        }

        $result = $tool->execute($args);

        // Hook: After tool execution, skip adding result to chat history if false returned
        if ($this->processAfterToolExecution($tool, $toolCall, $result) === false) {
            return null;
        }

        // Create tool result message directly - formatter will handle driver-specific conversion
        $content = is_string($result) ? $result : json_encode($result);

        return new ToolResultMessage($content, $toolCall->getId(), $toolCall->getToolName());
    }

    /**
     * Create a user message from a string
     *
     * @param  string  $content  The message content
     * @return MessageInterface The created user message
     */
    protected function createUserMessage(string $content): MessageInterface
    {
        return Message::user($content);
    }
}
