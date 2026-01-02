<?php

namespace LarAgent\BuiltIn\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

/**
 * Response containing an array of message symbols for batch symbolization.
 */
class MessageSymbolsResponse extends DataModel
{
    /**
     * Array of message symbols
     */
    #[Desc('Array of message symbols, one for each input message in the same order')]
    public MessageSymbolArray $symbols;
}
