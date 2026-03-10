<?php

namespace LarAgent\Core\Contracts;

use Closure;

/**
 * Interface for LLM drivers that support external hook injection.
 *
 * Drivers implementing this interface allow the orchestrator to inject
 * callbacks that fire before/after tool execution within the driver's
 * internal tool loop (e.g., when the SDK handles tools internally).
 */
interface HookableDriver
{
    /**
     * Set hook callbacks for tool execution.
     *
     * @param  Closure|null  $before  Called before tool execution. Return false to cancel.
     * @param  Closure|null  $after  Called after tool execution.
     */
    public function setHookCallbacks(?Closure $before, ?Closure $after): static;
}
