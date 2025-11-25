<?php

namespace LarAgent\Context;

use LarAgent\Context\Contracts\StorageManager as StorageManagerContract;
use LarAgent\Context\Contracts\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use InvalidArgumentException;
use Exception;

class StorageManager implements StorageManagerContract
{
    protected StorageDriver $primaryDriver;
    
    /**
     * @var StorageDriver[]
     */
    protected array $secondaryDrivers = [];

    public function __construct(array $drivers)
    {
        if (empty($drivers)) {
            throw new InvalidArgumentException("At least one storage driver must be provided.");
        }

        // Resolve all drivers
        $resolvedDrivers = array_map([$this, 'resolveDriver'], $drivers);

        // First one is primary
        $this->primaryDriver = array_shift($resolvedDrivers);
        
        // Rest are secondary
        $this->secondaryDrivers = $resolvedDrivers;
    }

    protected function resolveDriver(string|StorageDriver $driver): StorageDriver
    {
        if (is_string($driver)) {
            if (!class_exists($driver)) {
                throw new InvalidArgumentException("Storage class {$driver} does not exist.");
            }
            $driver = new $driver();
        }

        if (!$driver instanceof StorageDriver) {
            throw new InvalidArgumentException("Driver must implement " . StorageDriver::class . " interface.");
        }

        return $driver;
    }

    public function read(SessionIdentity $identity): array
    {
        // Try primary first
        try {
            $result = $this->primaryDriver->readFromMemory($identity);
            if (!empty($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            // If primary fails, continue to secondary
        }

        // Try secondary drivers
        foreach ($this->secondaryDrivers as $driver) {
            try {
                $result = $driver->readFromMemory($identity);
                if (!empty($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // If we get here, all drivers failed or returned empty
        throw new Exception("Failed to read from any storage driver.");
    }

    public function save(SessionIdentity $identity, array $data): void
    {
        // Write to primary
        try {
            $this->primaryDriver->writeToMemory($identity, $data);
        } catch (\Throwable $e) {
            // Continue writing to others even if primary fails
        }

        // Write to secondaries
        foreach ($this->secondaryDrivers as $driver) {
            try {
                $driver->writeToMemory($identity, $data);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }
}
