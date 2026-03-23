<?php

namespace LarAgent\Concerns;

use LarAgent\Drivers\LaravelAi\MessageConverter;
use LarAgent\Drivers\LaravelAi\SdkToolBridge;

/**
 * Trait that makes a LarAgent Agent compatible with the Laravel AI SDK Agent contract.
 *
 * Add this trait to your LarAgent agent class to make it usable as an SDK agent:
 *
 *     class MyAgent extends \LarAgent\Agent implements \Laravel\Ai\Contracts\Agent
 *     {
 *         use ImplementsSdkAgent;
 *     }
 *
 * Then you can use your LarAgent agent anywhere the SDK expects an Agent:
 *
 *     $response = (new MyAgent)->prompt('Hello!');
 */
trait ImplementsSdkAgent
{
    /**
     * Get the agent's tools as SDK-compatible tool bridges.
     *
     * @return iterable<\Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        $larAgentTools = $this->getTools();

        return SdkToolBridge::fromLarAgentTools($larAgentTools);
    }

    /**
     * Get the conversation messages in SDK format.
     *
     * @return iterable<\Laravel\Ai\Messages\Message>
     */
    public function messages(): iterable
    {
        // Access chat history through the Agent's HasContext trait method
        $chatHistory = $this->chatHistory();
        if ($chatHistory === null) {
            return [];
        }

        $messages = $chatHistory->getMessages()->all();

        // Extract and discard system instructions (handled separately by the SDK)
        [, $messages] = MessageConverter::extractInstructions($messages);

        return MessageConverter::toLaravelAiMessages($messages);
    }
}
