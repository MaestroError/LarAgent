<?php

namespace LarAgent\Tools\LaravelAi;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Tool;

/**
 * LarAgent tool that generates text embeddings via the Laravel AI SDK.
 * Requires the laravel/ai package to be installed.
 */
class EmbeddingTool extends Tool
{
    protected string $name = 'generate_embedding';

    protected string $description = 'Generate a vector embedding for the given text. Returns a JSON array of floats representing the embedding vector.';

    protected array $properties = [
        'text' => [
            'type' => 'string',
            'description' => 'The text to generate an embedding for',
        ],
    ];

    protected array $required = ['text'];

    protected ?string $provider = null;

    protected ?string $model = null;

    /**
     * Set the embedding provider.
     */
    public function usingProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Set the embedding model.
     */
    public function usingModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    protected function handle(array|DataModelContract $input): mixed
    {
        if (! function_exists('Laravel\\Ai\\embed')) {
            throw new \RuntimeException('The laravel/ai package is required. Install it with: composer require laravel/ai');
        }

        $text = is_array($input) ? ($input['text'] ?? '') : (string) $input;

        $embedFn = 'Laravel\\Ai\\embed';
        $embedding = $embedFn($text, provider: $this->provider, model: $this->model);

        return json_encode($embedding->embedding);
    }
}
