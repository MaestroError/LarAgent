<?php

namespace LarAgent\Drivers\OpenAi;

class OllamaDriver extends OpenAiCompatible
{
    protected string $default_api_url = 'http://localhost:11434/v1';
    protected string $default_api_key = 'ollama';

    public function __construct(array $provider = [])
    {
        $provider['api_key'] = $provider['api_key'] ?? $this->default_api_key;
        $provider['api_url'] = $provider['api_url'] ?? $this->default_api_url;
        parent::__construct($provider);
        $this->client = $this->buildClient($provider['api_key'], $provider['api_url']);
    }
}
