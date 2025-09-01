<?php

namespace LarAgent\Drivers\OpenAi;

class OpenRouter extends OpenAiCompatible
{
    protected string $default_api_url = 'https://openrouter.ai/api/v1';

    protected string $referer = 'https://laragent.ai/';

    protected string $title = 'LarAgent';

    public function __construct(array $provider = [])
    {
        // Set default values
        $provider['api_url'] = $provider['api_url'] ?? $this->default_api_url;
        $this->referer = $provider['referer'] ?? $this->referer;
        $this->title = $provider['title'] ?? $this->title;

        // Construct parent class and client
        parent::__construct($provider);
        $this->client = $this->buildClient($provider['api_key'], $provider['api_url'], [
            'HTTP-Referer' => $this->referer,
            'X-Title' => $this->title,
        ]);
    }
}
