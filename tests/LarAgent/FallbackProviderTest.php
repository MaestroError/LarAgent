<?php

use LarAgent\Agent;
use LarAgent\Messages\StreamedAssistantMessage;
use LarAgent\Tests\LarAgent\Fakes\FailingLlmDriver;
use LarAgent\Tests\LarAgent\Fakes\PreloadedFakeLlmDriver;

class FallbackTestAgent extends Agent
{
    protected $model = 'gpt-test';

    protected $history = 'in_memory';

    protected $provider = 'fail';

    public function instructions()
    {
        return 'Fallback test agent.';
    }
}

beforeEach(function () {
    config()->set('laragent.providers', [
        'fail' => [
            'label' => 'fail',
            'driver' => FailingLlmDriver::class,
            'model' => 'gpt-test',
            'api_key' => 'fail',
            'default_context_window' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
        'success' => [
            'label' => 'success',
            'driver' => PreloadedFakeLlmDriver::class,
            'model' => 'gpt-test',
            'api_key' => 'success',
            'default_context_window' => 10,
            'default_max_completion_tokens' => 10,
            'default_temperature' => 1,
        ],
    ]);
    config()->set('laragent.fallback_provider', 'success');
});

it('falls back to secondary provider on respond failure', function () {
    $agent = new FallbackTestAgent('test_key');

    $response = $agent->respond('Hello');

    expect($response)->toBe('fallback response');
});

it('falls back to secondary provider on streamed failure', function () {
    $agent = new FallbackTestAgent('test_key');

    $stream = $agent->respondStreamed('Hello');

    $chunks = [];
    foreach ($stream as $message) {
        if ($message instanceof StreamedAssistantMessage || $message instanceof \LarAgent\Messages\AssistantMessage) {
            $chunks[] = $message->getContent();
        }
    }

    expect($chunks)->not->toBeEmpty();
    expect(end($chunks))->toBe('fallback response');
});
