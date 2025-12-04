<?php

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Abstractions\DataModelArray;

class TestItem extends DataModel {
    public string $name;
}

class TestItemList extends DataModelArray {
    public static function allowedModels(): array {
        return [TestItem::class];
    }
}

class TextContent extends DataModel {
    public string $type = 'text';
    public string $text;
}

class ImageContent extends DataModel {
    public string $type = 'image';
    public string $url;
}

class ContentList extends DataModelArray {
    public static function allowedModels(): array {
        return [
            'text' => TextContent::class,
            'image' => ImageContent::class
        ];
    }
}

class TestContainer extends DataModel {
    public TestItemList $items;
    public ContentList $contents;
}

test('DataModelArray: Handles single type list', function () {
    $data = [
        ['name' => 'Item 1'],
        ['name' => 'Item 2'],
    ];

    $list = new TestItemList($data);

    expect($list)->toHaveCount(2);
    expect($list[0])->toBeInstanceOf(TestItem::class);
    expect($list[0]->name)->toBe('Item 1');
});

test('DataModelArray: Handles polymorphic list', function () {
    $data = [
        ['type' => 'text', 'text' => 'Hello'],
        ['type' => 'image', 'url' => 'http://example.com/img.png'],
    ];

    $list = new ContentList($data);

    expect($list)->toHaveCount(2);
    expect($list[0])->toBeInstanceOf(TextContent::class);
    expect($list[1])->toBeInstanceOf(ImageContent::class);
});

test('DataModelArray: Throws exception for invalid polymorphic type', function () {
    $data = [
        ['type' => 'unknown', 'data' => '???'],
    ];

    expect(fn() => new ContentList($data))
        ->toThrow(InvalidArgumentException::class);
});

test('DataModelArray: Integration with DataModel', function () {
    $data = [
        'items' => [
            ['name' => 'Nested Item']
        ],
        'contents' => [
            ['type' => 'text', 'text' => 'Nested Text']
        ]
    ];

    $container = TestContainer::fromArray($data);

    expect($container->items)->toBeInstanceOf(TestItemList::class);
    expect($container->items[0]->name)->toBe('Nested Item');
    expect($container->contents)->toBeInstanceOf(ContentList::class);
    expect($container->contents[0]->text)->toBe('Nested Text');
});

test('DataModelArray: Generates correct schema', function () {
    $list = new ContentList();
    $schema = $list->toSchema();

    expect($schema['type'])->toBe('array');
    expect($schema['items'])->toHaveKey('oneOf');
    expect($schema['items']['oneOf'])->toHaveCount(2);
});
