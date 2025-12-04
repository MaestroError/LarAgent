<?php

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Abstractions\DataModelArray;

class TestItem extends DataModel
{
    public string $name;
}

class TestArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [TestItem::class];
    }
}

test('DataModelArray constructor accepts array of items', function () {
    $item1 = new TestItem;
    $item1->name = 'one';
    $item2 = new TestItem;
    $item2->name = 'two';

    $list = new TestArray([$item1, $item2]);

    expect($list)->toHaveCount(2);
    expect($list[0]->name)->toBe('one');
});

test('DataModelArray constructor accepts variadic items', function () {
    $item1 = new TestItem;
    $item1->name = 'one';
    $item2 = new TestItem;
    $item2->name = 'two';

    $list = new TestArray($item1, $item2);

    expect($list)->toHaveCount(2);
    expect($list[0]->name)->toBe('one');
});

test('DataModelArray constructor accepts single item', function () {
    $item1 = new TestItem;
    $item1->name = 'one';

    $list = new TestArray($item1);

    expect($list)->toHaveCount(1);
    expect($list[0]->name)->toBe('one');
});

test('DataModelArray constructor accepts single array item definition', function () {
    $list = new TestArray(['name' => 'one']);

    expect($list)->toHaveCount(1);
    expect($list[0]->name)->toBe('one');
});
