<?php

namespace LarAgent\Context\Contracts;

use LarAgent\Messages\DataModels\MessageArray;

interface TruncationStrategy
{
    /**
     * Apply truncation to messages array.
     *
     * @param  MessageArray  $messages  Current chat history
     * @param  int  $truncationThreshold  Maximum allowed tokens (effective threshold after buffer)
     * @param  int  $currentTokens  Current total token count
     * @return MessageArray Truncated messages
     */
    public function truncate(MessageArray $messages, int $truncationThreshold, int $currentTokens): MessageArray;
}
