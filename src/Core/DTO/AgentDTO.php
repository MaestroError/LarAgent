<?php

namespace LarAgent\Core\DTO;

class AgentDTO
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerName,
        public readonly ?string $message,
        public readonly array $tools = [],
        public readonly ?string $instructions = null,
        public readonly ?array $responseSchema = [],
        public readonly array $configuration = []
    ) {}

    /**
     * Convert DTO to array
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'providerName' => $this->providerName,
            'message' => $this->message,
            'tools' => $this->tools,
            'instructions' => $this->instructions,
            'responseSchema' => $this->responseSchema,
            'configuration' => $this->configuration,
        ];
    }
}
