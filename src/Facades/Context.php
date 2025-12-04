<?php

namespace LarAgent\Facades;

use Illuminate\Support\Facades\Facade;
use LarAgent\Context\ContextManager;
use LarAgent\Context\NamedContextManager;

/**
 * Context Facade for managing agent contexts and storages.
 * 
 * Provides two entry points:
 * 1. `Context::of(AgentClass::class)` - Full agent-based context access (creates temp agent)
 * 2. `Context::named('AgentName')` - Lightweight context access via agent name string
 * 
 * @method static ContextManager of(string $agentClass) Create a context manager for an agent class
 * @method static ContextManager agent(string $agentClass) Alias for of()
 * @method static NamedContextManager named(string $agentName) Create a named context manager (lightweight)
 * 
 * @see \LarAgent\Context\ContextManager
 * @see \LarAgent\Context\NamedContextManager
 */
class Context extends Facade
{
    /**
     * Create a named context manager for lightweight access.
     * Does not require agent class initialization.
     *
     * @param string $agentName The agent name (without namespace)
     * @return NamedContextManager
     */
    public static function named(string $agentName): NamedContextManager
    {
        return NamedContextManager::named($agentName);
    }

    protected static function getFacadeAccessor(): string
    {
        return ContextManager::class;
    }
}
