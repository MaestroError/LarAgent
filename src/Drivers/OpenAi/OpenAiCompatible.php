<?php

namespace LarAgent\Drivers\OpenAi;

use OpenAI;

class OpenAiCompatible extends BaseOpenAiDriver
{
    protected string $default_url = 'https://api.openai.com/v1';

    public function __construct(array $provider = [])
    {
        parent::__construct($provider);
        if ($provider['api_key']) {
            $this->client = $this->buildClient($provider['api_key'], $provider['api_url'] ?? $this->default_url);
        } else {
            throw new \Exception('OpenAiCompatible driver requires api_key in provider settings.');
        }
    }

    protected function buildClient(string $apiKey, string $baseUrl, array $headers = []): mixed
    {
        $client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpClient($httpClient = new \GuzzleHttp\Client([]));

        foreach ($headers as $key => $value) {
            $client->withHttpHeader($key, $value);
        }

        return $client->make();
    }
}
