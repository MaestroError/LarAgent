<?php

namespace LarAgent\Usage\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Usage\Storages\UsageStorage;

class UsageStorageSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly UsageStorage $storage
    ) {}
}
