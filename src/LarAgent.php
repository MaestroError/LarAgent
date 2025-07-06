<?php

namespace LarAgent;

use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\ToolCall as ToolCallInterface;
use LarAgent\Core\Traits\Hooks;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;

class LarAgent
{
    use Hooks;

    protected string $model = 'gpt-4o-mini';

    protected int $contextWindowSize = 50000;

    protected int $maxCompletionTokens = 1000;

    protected float $temperature = 1.0;

    protected ?int $n = null;

    protected ?float $topP = null;

    protected ?float $frequencyPenalty = null;

    protected ?float $presencePenalty = null;

    protected int $reinjectInstructionsPer = 0; // 0 Means never

    protected ?bool $parallelToolCalls = true;

    protected bool $useDeveloperForInstructions = false;

    protected string $instructions;

    protected ?MessageInterface $message;

    protected array $responseSchema;

    protected LlmDriverInterface $driver;

    protected ChatHistoryInterface $chatHistory;

    protected array $tools = [];

    /** @var string|array|null */
    protected $toolChoice = null;

    /** @var bool Enable streaming mode */
    protected bool $streaming = false;

    /** @var callable|null Callback function for streaming */
    protected $streamCallback = null;

    protected array $modalities = [];

    protected ?array $audio = null;

    // Config methods

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function useModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getContextWindowSize(): int
    {
        return $this->contextWindowSize;
    }

    public function setContextWindowSize(int $contextWindowSize): self
    {
        $this->contextWindowSize = $contextWindowSize;

        return $this;
    }

    public function getMaxCompletionTokens(): int
    {
        return $this->maxCompletionTokens;
    }

    public function setMaxCompletionTokens(int $maxCompletionTokens): self
    {
        $this->maxCompletionTokens = $maxCompletionTokens;

        return $this;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function getN(): ?int
    {
        return $this->n;
    }

    public function setN(?int $n): self
    {
        $this->n = $n;

        return $this;
    }

    public function getTopP(): ?float
    {
        return $this->topP;
    }

    public function setTopP(?float $topP): self
    {
        $this->topP = $topP;

        return $this;
    }

    public function getFrequencyPenalty(): ?float
    {
        return $this->frequencyPenalty;
    }

    public function setFrequencyPenalty(?float $frequencyPenalty): self
    {
        $this->frequencyPenalty = $frequencyPenalty;

        return $this;
    }

    public function getPresencePenalty(): ?float
    {
        return $this->presencePenalty;
    }

    public function setPresencePenalty(?float $presencePenalty): self
    {
        $this->presencePenalty = $presencePenalty;

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
        $this->toolChoice = 'auto';

        return $this;
    }

    /**
     * Set tool choice to 'none' - prevent the model from using any tools
     * This simulates the behavior of not passing any functions
     */
    public function toolNone(): self
    {
        $this->toolChoice = 'none';

        return $this;
    }

    /**
     * Set tool choice to 'required' - model must use at least one tool
     * Only applies if tools are registered.
     */
    public function toolRequired(): self
    {
        $this->toolChoice = 'required';

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
        $this->toolChoice = [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
            ],
        ];

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
        if (empty($this->tools) || $this->toolChoice === null) {
            return null;
        }

        // If choice is 'none', always return it even without tools
        if ($this->toolChoice === 'none') {
            return 'none';
        }

        // For other choices, only return if tools are registered
        return $this->toolChoice;
    }

    /**
     * Set the tool choice configuration
     *
     * @param  string|array|null  $toolChoice  Tool choice configuration
     * @return $this
     */
    public function setToolChoice(string|array|null $toolChoice): self
    {
        $this->toolChoice = $toolChoice;

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
        return $this->parallelToolCalls;
    }

    public function setParallelToolCalls(?bool $parallelToolCalls): self
    {
        $this->parallelToolCalls = $parallelToolCalls;

        return $this;
    }

    public function getModalities(): array
    {
        return $this->modalities;
    }

    public function setModalities(array $modalities): self
    {
        $this->modalities = $modalities;

        return $this;
    }

    public function getAudio(): ?array
    {
        return $this->audio;
    }

    public function setAudio(?array $audio): self
    {
        $this->audio = $audio;

        return $this;
    }

    // Main API methods

    public function __construct(LlmDriverInterface $driver, ChatHistoryInterface $chatHistory)
    {
        $this->driver = $driver;
        $this->chatHistory = $chatHistory;
    }

    public static function setup(LlmDriverInterface $driver, ChatHistoryInterface $chatHistory, array $configs = []): self
    {
        $agent = new self($driver, $chatHistory);
        $agent->setConfigs($configs);

        return $agent;
    }

    public function setConfigs(array $configs): void
    {
        $this->contextWindowSize = $configs['contextWindowSize'] ?? $this->contextWindowSize;
        $this->maxCompletionTokens = $configs['maxCompletionTokens'] ?? $this->maxCompletionTokens;
        $this->temperature = $configs['temperature'] ?? $this->temperature;
        $this->reinjectInstructionsPer = $configs['reinjectInstructionsPer'] ?? $this->reinjectInstructionsPer;
        $this->model = $configs['model'] ?? $this->model;
        $this->n = $configs['n'] ?? $this->n;
        $this->topP = $configs['topP'] ?? $this->topP;
        $this->frequencyPenalty = $configs['frequencyPenalty'] ?? $this->frequencyPenalty;
        $this->presencePenalty = $configs['presencePenalty'] ?? $this->presencePenalty;
        $this->parallelToolCalls = array_key_exists('parallelToolCalls', $configs) ? $configs['parallelToolCalls'] : $this->parallelToolCalls;
        $this->toolChoice = $configs['toolChoice'] ?? $this->toolChoice;
        $this->modalities = $configs['modalities'] ?? $this->modalities;
        $this->audio = $configs['audio'] ?? $this->audio;
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
            $this->chatHistory->toArray(),
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

        $response = $this->driver->sendMessage($this->chatHistory->toArray(), $this->buildConfig());
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
            $array = json_decode($response->getContent(), true);
            // Hook: Before structured output response
            if ($this->processBeforeStructuredOutput($array) === false) {
                return null;
            }

            return $array;
        }

        return $response;
    }

    protected function buildConfig(): array
    {
        $configs = [
            'model' => $this->getModel(),
            'max_completion_tokens' => $this->getMaxCompletionTokens(),
            'temperature' => $this->getTemperature(),
        ];

        if ($this->getN() !== null) {
            $configs['n'] = $this->getN();
        }

        if ($this->getTopP() !== null) {
            $configs['top_p'] = $this->getTopP();
        }

        if ($this->getFrequencyPenalty() !== null) {
            $configs['frequency_penalty'] = $this->getFrequencyPenalty();
        }

        if ($this->getPresencePenalty() !== null) {
            $configs['presence_penalty'] = $this->getPresencePenalty();
        }

        if (! empty($this->tools)) {
            $PTC = $this->getParallelToolCalls();
            if ($PTC !== null) {
                $configs['parallel_tool_calls'] = $PTC;
            }

            $toolChoice = $this->getToolChoice();
            if ($toolChoice !== null) {
                $configs['tool_choice'] = $toolChoice;
            }
        }

        if (! empty($this->modalities)) {
            $configs['modalities'] = $this->modalities;
        }

        if (! empty($this->audio)) {
            $configs['audio'] = $this->audio;
        }

        return $configs;
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
        if ($this->processBeforeToolExecution($tool) === false) {
            return null;
        }

        $result = $tool->execute($args);

        // Hook: After tool execution, skip adding result to chat history if false returned
        if ($this->processAfterToolExecution($tool, $result) === false) {
            return null;
        }

        // Build tool result message content
        $messageArray = $this->driver->toolResultToMessage($toolCall, $result);

        return new ToolResultMessage($messageArray);
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
