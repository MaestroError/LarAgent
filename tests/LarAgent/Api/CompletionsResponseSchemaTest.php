<?php

use Illuminate\Http\Request;
use LarAgent\API\Completions;
use LarAgent\Agent;
use LarAgent\Tests\LarAgent\Fakes\FakeLlmDriver;

class SchemaDummyAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    protected $history = 'in_memory';
    protected $driver = FakeLlmDriver::class;

    public static ?array $capturedSchema = null;

    public function instructions()
    {
        return 'You are a dummy agent.';
    }

    public function prompt($message)
    {
        return $message;
    }

    protected function onInitialize()
    {
        $this->llmDriver->addMockResponse('stop', [
            'content' => json_encode(['foo' => 'bar']),
        ]);
    }

    public function respond(?string $message = null): string|array
    {
        $response = parent::respond($message);
        self::$capturedSchema = $this->structuredOutput();

        return is_array($response) ? json_encode($response) : $response;
    }
}

it('passes response schema from request to agent', function () {
    SchemaDummyAgent::$capturedSchema = null;

    $schema = [
        'type' => 'object',
        'properties' => [
            'foo' => ['type' => 'string'],
        ],
    ];

    $request = Request::create('/api/completions', 'POST', [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => 'hi'],
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => $schema,
        ],
    ]);

    Completions::make($request, SchemaDummyAgent::class);

    expect(SchemaDummyAgent::$capturedSchema)->toBe($schema);
});
