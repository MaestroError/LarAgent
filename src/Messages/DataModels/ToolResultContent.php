<?php

namespace LarAgent\Messages\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ToolResultContent extends DataModel
{
    #[Desc('The result content from the tool')]
    public string $content;

    #[Desc('The ID of the tool call this result responds to')]
    public string $tool_call_id;

    #[Desc('The name of the tool that was called')]
    public string $tool_name;

    public function __construct(string $content, string $toolCallId, string $toolName = '')
    {
        $this->content = $content;
        $this->tool_call_id = $toolCallId;
        $this->tool_name = $toolName;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'tool_call_id' => $this->tool_call_id,
            'tool_name' => $this->tool_name,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['content'],
            $data['tool_call_id'],
            $data['tool_name'] ?? ''
        );
    }
}
