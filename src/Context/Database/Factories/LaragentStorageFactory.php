<?php

namespace LarAgent\Context\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LarAgent\Context\Models\LaragentStorage;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\LarAgent\Context\Models\LaragentStorage>
 */
class LaragentStorageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = LaragentStorage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(),
            'data' => [
                'sample' => $this->faker->sentence(),
                'items' => [
                    ['name' => $this->faker->name(), 'value' => $this->faker->randomNumber()],
                ],
            ],
        ];
    }

    /**
     * Indicate that the storage has empty data.
     */
    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'data' => [],
        ]);
    }

    /**
     * Create storage with a specific key.
     */
    public function withKey(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => $key,
        ]);
    }

    /**
     * Create storage with specific data.
     */
    public function withData(array $data): static
    {
        return $this->state(fn (array $attributes) => [
            'data' => $data,
        ]);
    }
}
