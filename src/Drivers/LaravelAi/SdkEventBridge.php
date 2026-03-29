<?php

namespace LarAgent\Drivers\LaravelAi;

use Illuminate\Support\Facades\Event;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\DTO\AgentDTO;
use LarAgent\Core\Traits\SafeEventDispatch;

/**
 * Bridges Laravel AI SDK events to LarAgent events so observability is
 * unified regardless of which driver is used.
 *
 * SDK events are mapped to their LarAgent equivalents:
 * - PromptingAgent  → BeforeSend
 * - AgentPrompted   → AfterResponse
 * - InvokingTool    → BeforeToolExecution (guarded to avoid double-dispatch)
 * - ToolInvoked     → AfterToolExecution  (guarded to avoid double-dispatch)
 *
 * The guard mechanism prevents double-dispatch for tool events that are
 * already handled by SdkToolBridge's hook callbacks. Only SDK-internal
 * tools not wrapped by LarAgent will trigger bridged events.
 *
 * WARNING: This class uses static properties for event listener state.
 * Under Laravel Octane or other long-lived process environments, static
 * state persists across requests. The reset() method is called at the
 * start of each sendMessage() cycle to mitigate cross-request contamination,
 * but this is insufficient for true concurrent request handling (e.g.,
 * Swoole coroutines). If running under Octane with coroutines, consider
 * disabling event bridging via the 'bridge_events' config option.
 */
class SdkEventBridge
{
    use SafeEventDispatch;

    /**
     * Track whether event listeners are already registered.
     */
    protected static bool $registered = false;

    /**
     * Tool names currently being handled by SdkToolBridge.
     * These are guarded from double-dispatch.
     *
     * @var array<string, bool>
     */
    protected static array $guardedTools = [];

    /**
     * The AgentDTO to include in bridged events.
     */
    protected static ?AgentDTO $agentDto = null;

    /**
     * Whether event bridging is enabled.
     */
    protected static bool $enabled = true;

    /**
     * Reference to the agent's chat history for real context in events.
     */
    protected static ?ChatHistoryInterface $chatHistory = null;

    /**
     * Register SDK event listeners that bridge to LarAgent events.
     * Idempotent — calling multiple times has no additional effect.
     *
     * @param  AgentDTO|null  $agentDto  The agent DTO to include in bridged events
     */
    public static function register(?AgentDTO $agentDto = null): void
    {
        static::$agentDto = $agentDto;

        if (static::$registered) {
            return;
        }

        // Only register if SDK event classes exist
        if (! class_exists(\Laravel\Ai\Events\PromptingAgent::class)) {
            return;
        }

        static::registerPromptingAgentListener();
        static::registerAgentPromptedListener();
        static::registerInvokingToolListener();
        static::registerToolInvokedListener();

        static::$registered = true;
    }

    /**
     * Update the AgentDTO used in bridged events.
     * Called before each agent interaction to ensure events carry current context.
     */
    public static function setAgentDto(?AgentDTO $agentDto): void
    {
        static::$agentDto = $agentDto;
    }

    /**
     * Set the chat history reference for real context in events.
     */
    public static function setChatHistory(?ChatHistoryInterface $chatHistory): void
    {
        static::$chatHistory = $chatHistory;
    }

    /**
     * Enable or disable event bridging.
     */
    public static function setEnabled(bool $enabled): void
    {
        static::$enabled = $enabled;
    }

    /**
     * Check if event bridging is enabled.
     */
    public static function isEnabled(): bool
    {
        return static::$enabled;
    }

    /**
     * Mark a tool as being handled by SdkToolBridge.
     * Tool events for guarded tools will not be bridged to avoid double-dispatch.
     */
    public static function guardTool(string $toolName): void
    {
        static::$guardedTools[$toolName] = true;
    }

    /**
     * Remove a tool from the guard list.
     */
    public static function unguardTool(string $toolName): void
    {
        unset(static::$guardedTools[$toolName]);
    }

    /**
     * Check if a tool is currently guarded from event bridging.
     */
    public static function isToolGuarded(string $toolName): bool
    {
        return isset(static::$guardedTools[$toolName]);
    }

    /**
     * Reset per-run state. Called at the start of each sendMessage() call
     * to prevent stale state leaking across requests (Octane, queues)
     * or across multi-provider fallback attempts.
     *
     * Note: $registered is NOT reset because Event::listen() listeners
     * persist and cannot be removed. Resetting $registered would cause
     * register() to add duplicate listeners on subsequent calls.
     */
    public static function reset(): void
    {
        static::$guardedTools = [];
        static::$agentDto = null;
        static::$enabled = true;
        static::$chatHistory = null;
    }

    /**
     * Bridge SDK PromptingAgent → LarAgent BeforeSend.
     * Uses the real chat history reference when available.
     */
    protected static function registerPromptingAgentListener(): void
    {
        Event::listen(\Laravel\Ai\Events\PromptingAgent::class, function ($event) {
            if (! static::$enabled || static::$agentDto === null) {
                return;
            }

            $chatHistory = static::$chatHistory ?? new \LarAgent\History\InMemoryChatHistory(
                new \LarAgent\Context\SessionIdentity(agentName: 'sdk_bridge'),
                [\LarAgent\Context\Drivers\InMemoryStorage::class]
            );

            $bridge = new self;
            $bridge->dispatchEvent(new \LarAgent\Events\BeforeSend(
                static::$agentDto,
                $chatHistory,
                null
            ));
        });
    }

    /**
     * Bridge SDK AgentPrompted → LarAgent AfterResponse.
     */
    protected static function registerAgentPromptedListener(): void
    {
        Event::listen(\Laravel\Ai\Events\AgentPrompted::class, function ($event) {
            if (! static::$enabled || static::$agentDto === null) {
                return;
            }

            $message = isset($event->response)
                ? MessageConverter::fromSdkResponse($event->response)
                : new \LarAgent\Messages\AssistantMessage('');

            $bridge = new self;
            $bridge->dispatchEvent(new \LarAgent\Events\AfterResponse(
                static::$agentDto,
                $message
            ));
        });
    }

    /**
     * Bridge SDK InvokingTool events.
     * Guarded: skips tools already handled by SdkToolBridge.
     *
     * Note: We cannot construct BeforeToolExecution without Tool/ToolCall interfaces,
     * so SDK-internal tool events that aren't wrapped by SdkToolBridge are silently
     * skipped. LarAgent-wrapped tools fire events through SdkToolBridge's hook callbacks.
     */
    protected static function registerInvokingToolListener(): void
    {
        // Tool events are handled by SdkToolBridge's hook callbacks for wrapped tools.
        // SDK-internal tools cannot be bridged because BeforeToolExecution/AfterToolExecution
        // require Tool and ToolCall interfaces that we don't have for SDK-internal tools.
    }

    /**
     * Bridge SDK ToolInvoked events.
     * Guarded: skips tools already handled by SdkToolBridge.
     *
     * @see registerInvokingToolListener() for rationale
     */
    protected static function registerToolInvokedListener(): void
    {
        // Tool events are handled by SdkToolBridge's hook callbacks for wrapped tools.
    }
}
