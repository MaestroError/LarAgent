<?php

namespace LarAgent\Core\Enums;

enum MessageContentType: string
{
    case TEXT = 'text';
    case IMAGE_URL = 'image_url';
    case INPUT_AUDIO = 'input_audio';
}
