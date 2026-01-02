<?php

namespace LarAgent\Core\Traits;

/**
 * Provides safe logging that handles cases where Laravel app may not be available.
 *
 * This trait should be used by any class that needs to log messages
 * and may operate in contexts where the Laravel container is not available
 * (e.g., during shutdown, in tests, or in standalone usage).
 */
trait UsesLogger
{
    /**
     * Log a debug message safely.
     *
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->safeLog('debug', $message, $context);
    }

    /**
     * Log an info message safely.
     *
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $this->safeLog('info', $message, $context);
    }

    /**
     * Log a warning message safely.
     *
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $this->safeLog('warning', $message, $context);
    }

    /**
     * Log an error message safely.
     *
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->safeLog('error', $message, $context);
    }

    /**
     * Safely log a message, handling cases where Laravel app may not be available.
     *
     * @param  string  $level  Log level (debug, info, warning, error)
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     */
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            // Check if logger helper function exists and app is bound
            if (! function_exists('logger')) {
                return;
            }

            // Check if Laravel app is available
            if (! function_exists('app') || ! app()->bound('log')) {
                return;
            }

            // Log the message with the appropriate level
            logger()->{$level}($message, $context);
        } catch (\Throwable $e) {
            // Silently ignore logging errors
            // This prevents cascading failures when logging itself fails
        }
    }
}
