<?php

namespace LarAgent\Core\Contracts;

interface InterruptableDriver
{
    /**
     * Signal the driver to stop streaming.
     */
    public function interrupt(): void;

    /**
     * Check whether an interrupt has been requested.
     */
    public function isInterrupted(): bool;

    /**
     * Clear the interrupt flag so the driver can be reused.
     */
    public function resetInterrupt(): void;
}
