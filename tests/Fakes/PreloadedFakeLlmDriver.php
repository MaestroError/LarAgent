<?php

namespace LarAgent\Tests\Fakes;

use LarAgent\Messages\AssistantMessage;

class PreloadedFakeLlmDriver extends FakeLlmDriver
{
    public function __construct()
    {
        $this->addMockResponse('stop', ['content' => 'fallback response']);
    }
}
