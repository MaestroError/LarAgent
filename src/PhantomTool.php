<?php

namespace LarAgent;

class PhantomTool extends Tool
{
    public static function create(string $name, string $description): Tool
    {
        return new self($name, $description);
    }
}
