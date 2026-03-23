<?php

namespace LarAgent\Tools\LaravelAi;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Tool;

/**
 * LarAgent tool that performs web searches via the Laravel AI SDK.
 * Wraps the SDK's WebSearch provider tool for use in LarAgent agents.
 * Requires the laravel/ai package to be installed.
 */
class WebSearchTool extends Tool
{
    protected string $name = 'web_search';

    protected string $description = 'Search the web for current information. Returns relevant search results.';

    protected array $properties = [
        'query' => [
            'type' => 'string',
            'description' => 'The search query',
        ],
    ];

    protected array $required = ['query'];

    protected int $maxResults = 5;

    protected array $allowedDomains = [];

    protected array $blockedDomains = [];

    /**
     * Set the maximum number of search results.
     */
    public function max(int $maxResults): self
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    /**
     * Set allowed domains for search results.
     *
     * @param  array<string>  $domains
     */
    public function allow(array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }

    /**
     * Set blocked domains for search results.
     *
     * @param  array<string>  $domains
     */
    public function block(array $domains): self
    {
        $this->blockedDomains = $domains;

        return $this;
    }

    protected function handle(array|DataModelContract $input): mixed
    {
        if (! class_exists(\Laravel\Ai\Providers\Tools\WebSearch::class)) {
            throw new \RuntimeException('The laravel/ai package is required. Install it with: composer require laravel/ai');
        }

        $query = is_array($input) ? ($input['query'] ?? '') : (string) $input;

        // Build the SDK WebSearch tool
        $sdkTool = (new \Laravel\Ai\Providers\Tools\WebSearch)->max($this->maxResults);

        if (! empty($this->allowedDomains)) {
            $sdkTool = $sdkTool->allow($this->allowedDomains);
        }

        if (! empty($this->blockedDomains)) {
            $sdkTool = $sdkTool->block($this->blockedDomains);
        }

        // Create a request for the SDK tool
        $request = new \Laravel\Ai\Tools\Request(['query' => $query]);

        return $sdkTool->handle($request);
    }
}
