<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\DTO\DriverConfig;
use OpenAI;

class OpenAiCompatible extends BaseOpenAiDriver
{
    protected string $default_url = 'https://api.openai.com/v1';

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        $apiKey = $this->getDriverConfig()->api_key;
        $apiUrl = $this->getDriverConfig()->api_url ?? $this->default_url;
        if ($apiKey) {
            $this->client = $this->buildClient($apiKey, $apiUrl);
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
