<?php

namespace LarAgent\Context\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LarAgent\Context\Models\LaragentMessage;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\LarAgent\Context\Models\LaragentMessage>
 */
class LaragentMessageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = LaragentMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_key' => $this->faker->uuid(),
            'position' => 0,
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'message_uuid' => 'msg_' . bin2hex(random_bytes(12)),
            'message_created' => now()->toIso8601String(),
        ];
    }

    /**
     * Set the session key.
     */
    public function forSession(string $sessionKey): static
    {
        return $this->state(fn (array $attributes) => [
            'session_key' => $sessionKey,
        ]);
    }

    /**
     * Set the position.
     */
    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }

    /**
     * Create a user message.
     */
    public function userMessage(string $content = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'user',
            'content' => $content ?? $this->faker->sentence(),
        ]);
    }

    /**
     * Create an assistant message.
     */
    public function assistantMessage(string $content = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'content' => $content ?? $this->faker->paragraph(),
        ]);
    }

    /**
     * Create a system message.
     */
    public function systemMessage(string $content = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'system',
            'content' => $content ?? 'You are a helpful assistant.',
        ]);
    }

    /**
     * Create a tool call message.
     */
    public function toolCallMessage(array $toolCalls = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls ?? [
                [
                    'id' => 'call_' . bin2hex(random_bytes(12)),
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'arguments' => json_encode(['location' => 'Boston']),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Create a tool result message.
     */
    public function toolResultMessage(string $toolCallId = null, string $content = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'tool',
            'content' => $content ?? json_encode(['temperature' => '72°F']),
            'tool_call_id' => $toolCallId ?? 'call_' . bin2hex(random_bytes(12)),
        ]);
    }

    /**
     * Add usage statistics.
     */
    public function withUsage(array $usage = null): static
    {
        return $this->state(fn (array $attributes) => [
            'usage' => $usage ?? [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
        ]);
    }

    /**
     * Add metadata.
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }

    /**
     * Add extras.
     */
    public function withExtras(array $extras): static
    {
        return $this->state(fn (array $attributes) => [
            'extras' => $extras,
        ]);
    }
}
