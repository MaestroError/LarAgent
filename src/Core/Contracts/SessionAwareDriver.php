<?php

namespace LarAgent\Core\Contracts;

use LarAgent\Context\Contracts\SessionIdentity;

/**
 * Interface for LLM drivers that need session identity context.
 *
 * Drivers implementing this interface receive the agent's session identity
 * before each request, enabling them to pass session/conversation context
 * to external systems (e.g., the Laravel AI SDK's conversation store).
 */
interface SessionAwareDriver
{
    /**
     * Set the session identity for the current request.
     *
     * @param  SessionIdentity  $identity  The session identity containing agent name, user ID, chat key, etc.
     * @return $this
     */
    public function setSessionIdentity(SessionIdentity $identity): static;

    /**
     * Get the current session identity, if set.
     */
    public function getSessionIdentity(): ?SessionIdentity;
}
