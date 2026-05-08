<?php

namespace LarAgent\Drivers\OpenAi;

use GuzzleHttp\Client;
use LarAgent\Core\DTO\DriverConfig;
use OpenAI;

/**
 * OpenAI Responses API compatible driver with custom base URL support.
 *
 * Use this for providers that offer a Responses-API-compatible endpoint.
 */
class OpenAiResponsesCompatible extends OpenAiResponsesDriver
{
    protected string $default_url = 'https://api.openai.com/v1';

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        $apiKey = $this->getDriverConfig()->apiKey;
        $apiUrl = $this->getDriverConfig()->apiUrl ?? $this->default_url;
        if ($apiKey) {
            $this->client = $this->buildClient($apiKey, $apiUrl);
        } else {
            throw new \Exception('OpenAiResponsesCompatible driver requires api_key in provider settings.');
        }
    }

    protected function buildClient(string $apiKey, string $baseUrl, array $headers = []): mixed
    {
        $client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpClient($httpClient = new Client([]));

        foreach ($headers as $key => $value) {
            $client->withHttpHeader($key, $value);
        }

        return $client->make();
    }
}
