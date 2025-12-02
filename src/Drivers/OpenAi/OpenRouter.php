<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\DTO\DriverConfig;

class OpenRouter extends OpenAiCompatible
{
    protected string $default_api_url = 'https://openrouter.ai/api/v1';

    protected string $referer = 'https://laragent.ai/';

    protected string $title = 'LarAgent';

    public function __construct(DriverConfig|array $settings = [])
    {
        // Convert to DriverConfig if needed
        $provided = is_array($settings) ? DriverConfig::fromArray($settings) : $settings;

        // Extract custom options from extras
        $this->referer = $provided->getExtra('referer', $this->referer);
        $this->title = $provided->getExtra('title', $this->title);

        // Create defaults config, then merge with provided settings (provided takes precedence)
        $defaults = new DriverConfig(
            apiUrl: $this->default_api_url,
        );
        $settings = $defaults->merge($provided);

        // Construct parent class and client
        parent::__construct($settings);
        $this->client = $this->buildClient(
            $this->getDriverConfig()->apiKey,
            $this->getDriverConfig()->apiUrl,
            [
                'HTTP-Referer' => $this->referer,
                'X-Title' => $this->title,
            ]
        );
    }
}
