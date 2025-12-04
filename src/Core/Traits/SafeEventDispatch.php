<?php

namespace LarAgent\Core\Traits;

/**
 * Provides safe event dispatching that handles Laravel app shutdown gracefully.
 * 
 * This trait should be used by any class that needs to dispatch events
 * and may be destroyed during PHP shutdown when the Laravel container
 * may no longer be available.
 */
trait SafeEventDispatch
{
    /**
     * Safely dispatch an event, handling cases where the Laravel app may not be available.
     * This prevents errors during shutdown when the container is being destroyed.
     *
     * @param object $event The event to dispatch
     * @return void
     */
    protected function dispatchEvent(object $event): void
    {
        // Skip if Event facade doesn't exist
        if (!class_exists('Illuminate\Support\Facades\Event')) {
            return;
        }

        // Check if Laravel app is available and running
        try {
            $app = \Illuminate\Support\Facades\Facade::getFacadeApplication();
            if ($app === null) {
                return;
            }
            
            // Try to actually resolve the events service - this will fail gracefully
            // if the container is being destroyed
            $events = $app->make('events');
            if ($events !== null) {
                $events->dispatch($event);
            }
        } catch (\Throwable $e) {
            // Silently ignore - app is likely shutting down
        }
    }
}
