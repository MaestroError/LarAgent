<?php

namespace LarAgent\Tools\LaravelAi;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Tool;

/**
 * LarAgent tool that generates images via the Laravel AI SDK.
 * Requires the laravel/ai package to be installed.
 */
class ImageGenerationTool extends Tool
{
    protected string $name = 'generate_image';

    protected string $description = 'Generate an image from a text prompt. Returns the URL of the generated image.';

    protected array $properties = [
        'prompt' => [
            'type' => 'string',
            'description' => 'A detailed description of the image to generate',
        ],
        'size' => [
            'type' => 'string',
            'description' => 'Image size (e.g., "1024x1024", "1792x1024", "1024x1792")',
        ],
    ];

    protected array $required = ['prompt'];

    protected ?string $provider = null;

    protected ?string $model = null;

    /**
     * Set the image generation provider.
     */
    public function usingProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Set the image generation model.
     */
    public function usingModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    protected function handle(array|DataModelContract $input): mixed
    {
        if (! function_exists('Laravel\\Ai\\image')) {
            throw new \RuntimeException('The laravel/ai package is required. Install it with: composer require laravel/ai');
        }

        $prompt = is_array($input) ? ($input['prompt'] ?? '') : (string) $input;
        $size = is_array($input) ? ($input['size'] ?? null) : null;

        $imageFn = 'Laravel\\Ai\\image';
        $args = ['provider' => $this->provider, 'model' => $this->model];
        if ($size !== null) {
            $args['size'] = $size;
        }
        $result = $imageFn($prompt, ...$args);

        return $result->url ?? json_encode($result);
    }
}
