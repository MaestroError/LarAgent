<?php

namespace LarAgent\Commands\Traits;

trait ClickableOutput
{
    protected function makeTextClickable(string $path): string
    {
        return '<href='.$path.'>'.$path.'</>';
    }
}
