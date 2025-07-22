<?php

namespace LarAgent\Tests\LarAgent\Fakes;

class PreloadedFakeLlmDriver extends FakeLlmDriver
{
    public function __construct()
    {
        $this->addMockResponse('stop', ['content' => 'fallback response']);
    }
}
