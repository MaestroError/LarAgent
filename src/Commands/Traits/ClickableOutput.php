<?php

namespace LarAgent\Commands\Traits;

trait ClickableOutput
{
    protected function makeTextClickable(string $path, string $label = null): string
    {
        $label = $label ? $label : $path;
        return '<href='.$path.'>'.$label.'</>';
    }
}
