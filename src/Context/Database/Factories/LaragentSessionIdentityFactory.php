<?php

namespace LarAgent\Context\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LarAgent\Context\Models\LaragentSessionIdentity;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\LarAgent\Context\Models\LaragentSessionIdentity>
 */
class LaragentSessionIdentityFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = LaragentSessionIdentity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agentName = 'TestAgent';
        $chatName = $this->faker->uuid();

        return [
            'session_key' => $this->faker->uuid(),
            'position' => 0,
            'key' => "{$agentName}_{$chatName}",
            'agent_name' => $agentName,
            'chat_name' => $chatName,
            'user_id' => null,
            'group' => null,
            'scope' => null,
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
     * Set specific agent name.
     */
    public function forAgent(string $agentName): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_name' => $agentName,
            'key' => "{$agentName}_{$attributes['chat_name']}",
        ]);
    }

    /**
     * Set specific chat name.
     */
    public function forChat(string $chatName): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_name' => $chatName,
            'key' => "{$attributes['agent_name']}_{$chatName}",
        ]);
    }

    /**
     * Set user ID.
     */
    public function forUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    /**
     * Set group.
     */
    public function inGroup(string $group): static
    {
        return $this->state(fn (array $attributes) => [
            'group' => $group,
        ]);
    }

    /**
     * Set scope.
     */
    public function withScope(string $scope): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => $scope,
            'key' => "{$scope}_{$attributes['agent_name']}_{$attributes['chat_name']}",
        ]);
    }
}
