<?php

namespace LarAgent\API\Completions\Traits;

use Illuminate\Support\Str;

trait HasSessionId
{
    protected function setSessionId()
    {
        return Str::random(10);
    }
}
