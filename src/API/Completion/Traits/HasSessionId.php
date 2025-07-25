<?php

namespace LarAgent\API\Completion\Traits;

use Illuminate\Support\Str;

trait HasSessionId
{
    protected function setSessionId()
    {
        return Str::random(10);
    }
}
