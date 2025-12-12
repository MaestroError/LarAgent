<?php

namespace LarAgent\BuiltIn\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

/**
 * Array of message symbols for batch symbolization.
 */
class MessageSymbolArray extends DataModelArray
{
    /**
     * Return the list of allowed DataModel classes.
     *
     * @return array<string>
     */
    public static function allowedModels(): array
    {
        return [MessageSymbol::class];
    }
}
