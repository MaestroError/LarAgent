<?php

namespace LarAgent\Messages;

use LarAgent\Core\Abstractions\Message;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Enums\Role;

class AssistantMessage extends Message implements MessageInterface
{
    public function __construct(string $content, array $metadata = [])
    {
        parent::__construct(Role::ASSISTANT->value, $content, $metadata);
    }

    /**
     * Override the __toString method to handle complex content structures
     * If the first element doesn't have a 'text' key, searches for the first
     * element with type='text' and returns its 'text' value
     */
    public function __toString(): string
    {
        $content = $this->getContent();
        if (is_string($content)) {
            return $content;
        } else {
            // Check if the first array element has a 'text' key
            if (isset($content[0]['text'])) {
                return $content[0]['text'];
            }

            // Find the first array element with type='text' and a 'text' key
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text' && isset($item['text'])) {
                    return $item['text'];
                }
            }

            // Return empty string if no text content found
            return '';
        }
    }
}
