<?php

use LarAgent\Messages\DataModels\Content\AudioContent;
use LarAgent\Messages\DataModels\Content\ImageContent;
use LarAgent\Messages\DataModels\Content\Parts\ImageUrl;
use LarAgent\Messages\DataModels\Content\Parts\InputAudio;
use LarAgent\Messages\DataModels\Content\TextContent;
use LarAgent\Messages\DataModels\MessageContent;

test('MessageContent: Handles text content', function () {
    $data = [
        ['type' => 'text', 'text' => 'Hello World'],
    ];

    $content = new MessageContent($data);

    expect($content)->toHaveCount(1);
    expect($content[0])->toBeInstanceOf(TextContent::class);
    expect($content[0]->text)->toBe('Hello World');
});

test('MessageContent: Handles image content', function () {
    $data = [
        [
            'type' => 'image_url',
            'image_url' => ['url' => 'http://example.com/image.png'],
        ],
    ];

    $content = new MessageContent($data);

    expect($content)->toHaveCount(1);
    expect($content[0])->toBeInstanceOf(ImageContent::class);
    expect($content[0]->image_url)->toBeInstanceOf(ImageUrl::class);
    expect($content[0]->image_url->url)->toBe('http://example.com/image.png');
});

test('MessageContent: Handles audio content', function () {
    $data = [
        [
            'type' => 'input_audio',
            'input_audio' => ['data' => 'base64...', 'format' => 'mp3'],
        ],
    ];

    $content = new MessageContent($data);

    expect($content)->toHaveCount(1);
    expect($content[0])->toBeInstanceOf(AudioContent::class);
    expect($content[0]->input_audio)->toBeInstanceOf(InputAudio::class);
    expect($content[0]->input_audio->format)->toBe('mp3');
});

test('MessageContent: Handles mixed content', function () {
    $data = [
        ['type' => 'text', 'text' => 'Look at this:'],
        [
            'type' => 'image_url',
            'image_url' => ['url' => 'http://example.com/image.png'],
        ],
    ];

    $content = new MessageContent($data);

    expect($content)->toHaveCount(2);
    expect($content[0])->toBeInstanceOf(TextContent::class);
    expect($content[1])->toBeInstanceOf(ImageContent::class);
});

test('MessageContent: Throws exception for invalid type', function () {
    $data = [
        ['type' => 'unknown', 'data' => '???'],
    ];

    expect(fn () => new MessageContent($data))
        ->toThrow(InvalidArgumentException::class);
});
