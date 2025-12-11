<?php

namespace LarAgent\Usage\Drivers;

use LarAgent\Context\Drivers\EloquentStorage;
use LarAgent\Usage\Models\LaragentUsage;

/**
 * Eloquent-based storage driver for usage records.
 *
 * Uses the LaragentUsage model by default.
 */
class EloquentUsageDriver extends EloquentStorage
{
    /**
     * Create a new Eloquent usage storage driver instance.
     *
     * @param  string|null  $model  The Eloquent model class to use for storage
     */
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?? LaragentUsage::class);
    }
}
