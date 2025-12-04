<?php

namespace LarAgent\Facades;

use Illuminate\Support\Facades\Facade;
use LarAgent\Context\ContextManager;

/**
 * Context Facade for managing agent contexts and storages.
 * 
 * @method static ContextManager agent(string $agentClass) Create a context manager for an agent class
 * 
 * @see \LarAgent\Context\ContextManager
 */
class Context extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContextManager::class;
    }
}
