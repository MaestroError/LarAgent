<?php

namespace LarAgent;

use LarAgent\Tool;

class PhantomTool extends Tool
{
    public static function create(string $name, string $description): Tool
    {
        return new self($name, $description);
    }
}
