<?php

namespace LarAgent\Context\Abstract;

use LarAgent\Context\Contracts\TruncationStrategy as TruncationStrategyContract;
use LarAgent\Core\Contracts\Message as MessageInterface;

abstract class TruncationStrategy implements TruncationStrategyContract
{
    /**
     * Configuration for this strategy
     *
     * @var array
     */
    protected array $config;

    /**
     * Create a new truncation strategy instance.
     *
     * @param  array  $config  Strategy configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaultConfig(), $config);
    }

    /**
     * Get the default configuration for this strategy.
     *
     * @return array Default configuration
     */
    abstract protected function defaultConfig(): array;

    /**
     * Check if message should be preserved (system/developer messages typically shouldn't be removed).
     *
     * @param  MessageInterface  $message  The message to check
     * @return bool True if message should be preserved
     */
    protected function shouldPreserve(MessageInterface $message): bool
    {
        if (! $this->getConfig('preserve_system', false)) {
            return false;
        }

        return in_array($message->getRole(), ['system', 'developer']);
    }

    /**
     * Get configuration value.
     *
     * @param  string  $key  Configuration key
     * @param  mixed  $default  Default value if key not found
     * @return mixed Configuration value
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
