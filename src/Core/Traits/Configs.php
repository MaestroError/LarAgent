<?php

namespace LarAgent\Core\Traits;

/**
 * Trait for managing agent configurations
 */
trait Configs
{
    /** @var array */
    protected $configs = [];

    public function withConfigs(array $configs): self
    {
        $this->configs = [...$this->configs, ...$configs];

        return $this;
    }

    public function setConfigs(array $configs): self
    {
        $this->configs = $configs;

        return $this;
    }

    public function getConfigs(): array
    {
        return $this->configs;
    }

    public function getConfig(string $key): mixed
    {
        return data_get($this->configs, $key);
    }

    public function hasConfig(string $key): bool
    {
        return data_get($this->configs, $key) !== null;
    }

    public function removeConfig(string $key): self
    {
        data_forget($this->configs, $key);

        return $this;
    }

    public function clearConfigs(): self
    {
        $this->configs = [];

        return $this;
    }

    public function setConfig(string $key, mixed $value): self
    {
        data_set($this->configs, $key, $value);

        return $this;
    }
}
