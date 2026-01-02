<?php

namespace LarAgent\Drivers\OpenAi;

use LarAgent\Core\DTO\DriverConfig;
use OpenAI;

class OpenAiDriver extends BaseOpenAiDriver
{
    protected mixed $client;

    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);
        $apiKey = $this->getDriverConfig()->apiKey;
        $this->client = $apiKey ? OpenAI::client($apiKey) : null;
    }
}
