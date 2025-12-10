<?php

namespace LarAgent\Usage\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LarAgent\Usage\DataModels\Usage;
use LarAgent\Usage\Storages\UsageStorage;

class UsageAdding
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly UsageStorage $storage,
        public readonly Usage $usage
    ) {}
}
