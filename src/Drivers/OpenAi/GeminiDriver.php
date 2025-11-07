<?php

namespace LarAgent\Drivers\OpenAi;

class GeminiDriver extends OpenAiCompatible
{
    protected string $default_url = 'https://generativelanguage.googleapis.com/v1beta/openai';

    public function __construct(array $provider = [])
    {
        parent::__construct($provider);
        if (isset($provider['api_key']) && $provider['api_key']) {
            $this->client = $this->buildClient($provider['api_key'], $provider['api_url'] ?? $this->default_url);
        } else {
            throw new \Exception('GeminiDriver driver requires api_key in provider settings.');
        }
    }
}
