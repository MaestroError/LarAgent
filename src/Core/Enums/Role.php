<?php

namespace LarAgent\Core\Enums;

enum Role: string
{
    case SYSTEM = 'system';
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case TOOL = 'tool';
    case FUNCTION = 'function';
    case DEVELOPER = 'developer';
}
