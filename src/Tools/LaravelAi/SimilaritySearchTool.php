<?php

namespace LarAgent\Tools\LaravelAi;

use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Tool;

/**
 * LarAgent tool that performs vector similarity search via the Laravel AI SDK.
 * Wraps the SDK's SimilaritySearch tool for use in LarAgent agents.
 * Requires the laravel/ai package to be installed.
 */
class SimilaritySearchTool extends Tool
{
    protected string $name = 'similarity_search';

    protected string $description = 'Search for documents similar to the given query using vector similarity. Returns matching documents ordered by relevance.';

    protected array $properties = [
        'query' => [
            'type' => 'string',
            'description' => 'The search query to find similar documents for',
        ],
    ];

    protected array $required = ['query'];

    protected string $modelClass;

    protected string $column;

    protected float $minSimilarity;

    protected int $limit;

    protected ?\Closure $queryCallback = null;

    public function __construct(
        string $modelClass,
        string $column = 'embedding',
        float $minSimilarity = 0.7,
        int $limit = 10,
        ?\Closure $queryCallback = null
    ) {
        parent::__construct();
        $this->modelClass = $modelClass;
        $this->column = $column;
        $this->minSimilarity = $minSimilarity;
        $this->limit = $limit;
        $this->queryCallback = $queryCallback;
    }

    /**
     * Create a new instance with fluent API.
     */
    public static function usingModel(
        string $modelClass,
        string $column = 'embedding',
        float $minSimilarity = 0.7,
        int $limit = 10,
        ?\Closure $queryCallback = null
    ): self {
        return new self($modelClass, $column, $minSimilarity, $limit, $queryCallback);
    }

    protected function handle(array|DataModelContract $input): mixed
    {
        if (! class_exists(\Laravel\Ai\Tools\SimilaritySearch::class)) {
            throw new \RuntimeException('The laravel/ai package is required. Install it with: composer require laravel/ai');
        }

        $query = is_array($input) ? ($input['query'] ?? '') : (string) $input;

        try {
            // Use the SDK's SimilaritySearch under the hood
            $sdkTool = \Laravel\Ai\Tools\SimilaritySearch::usingModel(
                model: $this->modelClass,
                column: $this->column,
                minSimilarity: $this->minSimilarity,
                limit: $this->limit,
                query: $this->queryCallback,
            );

            // Create a request for the SDK tool
            $request = new \Laravel\Ai\Tools\Request(['query' => $query]);

            return $sdkTool->handle($request);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'Similarity search failed: '.$e->getMessage()]);
        }
    }
}
