<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\DTO\DriverConfig;

class GeminiDriver extends OpenAiCompatible
{
    protected string $default_url = 'https://generativelanguage.googleapis.com/v1beta/openai';

    public function __construct(DriverConfig|array $settings = [])
    {
        // Create defaults config, then merge with provided settings (provided takes precedence)
        $defaults = new DriverConfig(
            apiUrl: $this->default_url,
        );

        $provided = is_array($settings) ? DriverConfig::fromArray($settings) : $settings;
        $settings = $defaults->merge($provided);

        parent::__construct($settings);

        $apiKey = $this->getDriverConfig()->apiKey;
        if ($apiKey) {
            $this->client = $this->buildClient($apiKey, $this->getDriverConfig()->apiUrl);
        } else {
            throw new \Exception('GeminiDriver driver requires api_key in provider settings.');
        }
    }
}
