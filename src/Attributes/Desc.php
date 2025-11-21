<?php

namespace LarAgent\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Desc
{
    public function __construct(
        public string $description
    ) {}
}
