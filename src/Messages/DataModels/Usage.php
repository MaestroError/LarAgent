<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class Usage extends DataModel
{
    #[Desc('Number of tokens in the prompt')]
    public int $promptTokens;

    #[Desc('Number of tokens in the completion')]
    public int $completionTokens;

    #[Desc('Total number of tokens used')]
    public int $totalTokens;

    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?int $totalTokens = null
    ) {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->totalTokens = $totalTokens ?? ($promptTokens + $completionTokens);
    }

    /**
     * Convert to array with snake_case keys (standard format).
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }

    /**
     * Create Usage from array.
     * Expects normalized keys: prompt_tokens, completion_tokens, total_tokens.
     * MessageFormatters are responsible for normalizing API-specific keys.
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['completion_tokens'] ?? 0),
            isset($data['total_tokens']) ? (int) $data['total_tokens'] : null
        );
    }
}
