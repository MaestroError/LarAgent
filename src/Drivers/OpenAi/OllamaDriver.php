<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\DTO\DriverConfig;

class OllamaDriver extends OpenAiCompatible
{
    protected string $default_api_url = 'http://localhost:11434/v1';

    protected string $default_api_key = 'ollama';

    public function __construct(DriverConfig|array $settings = [])
    {
        // Create defaults config, then merge with provided settings (provided takes precedence)
        $defaults = new DriverConfig(
            api_key: $this->default_api_key,
            api_url: $this->default_api_url,
        );

        $provided = is_array($settings) ? DriverConfig::fromArray($settings) : $settings;
        $settings = $defaults->merge($provided);

        parent::__construct($settings);
        $this->client = $this->buildClient(
            $this->getDriverConfig()->api_key,
            $this->getDriverConfig()->api_url
        );
    }
}
