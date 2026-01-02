<?php

/**
 * Manual test to demonstrate multi-type DataModel support
 * This shows how union types generate OpenAPI schemas with "oneOf"
 */

// Try to load vendor autoload, fall back to manual autoloading
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
} else {
    // Manual autoloading for testing without composer install
    spl_autoload_register(function ($class) {
        $prefix = 'LarAgent\\';
        $base_dir = __DIR__.'/../src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir.str_replace('\\', '/', $relative_class).'.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

// Example: User notification preferences with flexible types
enum NotificationChannel: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case PUSH = 'push';
}

enum Priority: int
{
    case LOW = 1;
    case MEDIUM = 2;
    case HIGH = 3;
}

class ContactInfo extends DataModel
{
    #[Desc('Email address')]
    public string $email;

    #[Desc('Phone number')]
    public ?string $phone = null;
}

class NotificationSettings extends DataModel
{
    #[Desc('User identifier - can be numeric ID or string username')]
    public string|int $userId;

    #[Desc('Notification enabled flag or specific channel')]
    public bool|NotificationChannel $enabled;

    #[Desc('Contact method - either a channel enum or full contact info object')]
    public NotificationChannel|ContactInfo $contact;

    #[Desc('Priority level - can be numeric or enum')]
    public int|Priority $priority;

    #[Desc('Flexible metadata - can be simple string, number, or complex structure')]
    public string|int|array $metadata;
}

echo "=== Multi-Type DataModel Demo ===\n\n";

// Generate OpenAPI schema
$schema = NotificationSettings::generateSchema();

echo "Generated OpenAPI Schema:\n";
echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Example 1: Using primitive values
echo "--- Example 1: Using Primitive Values ---\n";
$settings1 = NotificationSettings::fromArray([
    'userId' => 12345,  // numeric ID
    'enabled' => true,   // boolean
    'contact' => 'email', // will cast to enum (because NotificationChannel is listed first in the union type; this is order-dependent)
    'priority' => 2,     // numeric priority
    'metadata' => 'simple string',
]);

echo "Input: userId=12345, enabled=true, contact='email', priority=2\n";
echo 'Result: userId is '.gettype($settings1->userId).": {$settings1->userId}\n";
echo '        enabled is '.gettype($settings1->enabled).': '.var_export($settings1->enabled, true)."\n";
echo '        contact is '.get_class($settings1->contact).": {$settings1->contact->value}\n";
echo '        priority is '.get_debug_type($settings1->priority).': ';
if ($settings1->priority instanceof Priority) {
    echo $settings1->priority->value."\n";
} else {
    echo "{$settings1->priority}\n";
}
echo '        metadata is '.gettype($settings1->metadata).": {$settings1->metadata}\n\n";

// Example 2: Using enum and complex types
echo "--- Example 2: Using Enum and Complex Types ---\n";
$settings2 = NotificationSettings::fromArray([
    'userId' => 'john_doe',  // string username
    'enabled' => 'sms',       // will cast to enum
    'contact' => [            // will cast to ContactInfo
        'email' => 'john@example.com',
        'phone' => '+1234567890',
    ],
    'priority' => 3,          // will cast to Priority::HIGH
    'metadata' => ['key' => 'value', 'count' => 42],
]);

echo "Input: userId='john_doe', enabled='sms', contact={...}, priority=3\n";
echo 'Result: userId is '.gettype($settings2->userId).": {$settings2->userId}\n";
echo '        enabled is '.get_class($settings2->enabled).": {$settings2->enabled->value}\n";
echo '        contact is '.get_class($settings2->contact)."\n";
echo "          - email: {$settings2->contact->email}\n";
echo "          - phone: {$settings2->contact->phone}\n";
echo '        priority is '.get_debug_type($settings2->priority).': ';
if ($settings2->priority instanceof Priority) {
    echo $settings2->priority->value."\n";
} else {
    echo "{$settings2->priority}\n";
}
echo '        metadata is '.gettype($settings2->metadata).': '.json_encode($settings2->metadata)."\n\n";

// Example 3: Serialization back to array
echo "--- Example 3: Serialization ---\n";
$array = $settings2->toArray();
echo "Serialized back to array:\n";
echo json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

// Demonstrate oneOf in schema
echo "--- Schema Details: oneOf Examples ---\n\n";

echo "1. userId (string|int):\n";
echo json_encode($schema['properties']['userId'], JSON_PRETTY_PRINT)."\n\n";

echo "2. enabled (bool|NotificationChannel):\n";
echo json_encode($schema['properties']['enabled'], JSON_PRETTY_PRINT)."\n\n";

echo "3. contact (NotificationChannel|ContactInfo):\n";
echo json_encode($schema['properties']['contact'], JSON_PRETTY_PRINT)."\n\n";

echo "4. metadata (string|int|array):\n";
echo json_encode($schema['properties']['metadata'], JSON_PRETTY_PRINT)."\n\n";

echo "=== Demo Complete ===\n";

// ============================================
// Test 4: Multiple DataModels in Union Type
// ============================================
echo "\n\n=== Test 4: Multiple DataModels in Union Type (DataModel1|DataModel2) ===\n\n";

// Define two different DataModels
class TaskInfo extends DataModel
{
    #[Desc('Task title')]
    public string $title;

    #[Desc('Estimated minutes')]
    public int $estimatedMinutes;

    #[Desc('Task description')]
    public ?string $description = null;
}

class EventInfo extends DataModel
{
    #[Desc('Event name')]
    public string $name;

    #[Desc('Event location')]
    public string $location;

    #[Desc('Duration in hours')]
    public ?int $durationHours = null;
}

enum ItemType: string
{
    case TASK = 'task';
    case EVENT = 'event';
    case NOTE = 'note';
}

// DataModel with multi-DataModel union
class ItemContainer extends DataModel
{
    #[Desc('Item identifier')]
    public int $id;

    #[Desc('Item data - can be TaskInfo or EventInfo')]
    public TaskInfo|EventInfo $item;

    #[Desc('Complex union: TaskInfo, EventInfo, ItemType enum, or simple string')]
    public TaskInfo|EventInfo|ItemType|string $flexibleItem;
}

echo "--- Schema for ItemContainer ---\n";
$containerSchema = ItemContainer::generateSchema();
echo json_encode($containerSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

echo "--- Test 4a: TaskInfo in DataModel union ---\n";
$container1 = ItemContainer::fromArray([
    'id' => 1,
    'item' => [
        'title' => 'Review PR',
        'estimatedMinutes' => 45,
        'description' => 'Review the pull request for feature X',
    ],
    'flexibleItem' => 'simple string value',
]);

$isTaskInfo1 = $container1->item instanceof TaskInfo;
echo "Input: item = TaskInfo data (title, estimatedMinutes, description)\n";
echo 'Result: item is '.get_class($container1->item)."\n";
echo "  - title: {$container1->item->title}\n";
echo "  - estimatedMinutes: {$container1->item->estimatedMinutes}\n";
echo '  - description: '.($container1->item->description ?? 'null')."\n";
echo 'flexibleItem is '.gettype($container1->flexibleItem).": {$container1->flexibleItem}\n";
if ($isTaskInfo1) {
    echo "✓ TaskInfo correctly resolved\n\n";
} else {
    echo "✗ FAILED: Expected TaskInfo\n\n";
}

echo "--- Test 4b: EventInfo in DataModel union ---\n";
$container2 = ItemContainer::fromArray([
    'id' => 2,
    'item' => [
        'name' => 'Team Meeting',
        'location' => 'Conference Room A',
        'durationHours' => 2,
    ],
    'flexibleItem' => [
        'name' => 'Project Kickoff',
        'location' => 'Main Hall',
    ],
]);

$isEventInfo2 = $container2->item instanceof EventInfo;
$isEventInfoFlex2 = $container2->flexibleItem instanceof EventInfo;
echo "Input: item = EventInfo data (name, location, durationHours)\n";
echo 'Result: item is '.get_class($container2->item)."\n";
echo "  - name: {$container2->item->name}\n";
echo "  - location: {$container2->item->location}\n";
echo '  - durationHours: '.($container2->item->durationHours ?? 'null')."\n";
echo 'flexibleItem is '.get_debug_type($container2->flexibleItem)."\n";
if ($container2->flexibleItem instanceof EventInfo) {
    echo "  - name: {$container2->flexibleItem->name}\n";
    echo "  - location: {$container2->flexibleItem->location}\n";
}
if ($isEventInfo2 && $isEventInfoFlex2) {
    echo "✓ EventInfo correctly resolved for both\n\n";
} else {
    echo '✗ FAILED: Expected EventInfo for item='.($isEventInfo2 ? 'OK' : 'FAIL').', flexibleItem='.($isEventInfoFlex2 ? 'OK' : 'FAIL')."\n\n";
}

echo "--- Test 4c: Enum in complex union ---\n";
$container3 = ItemContainer::fromArray([
    'id' => 3,
    'item' => [
        'title' => 'Placeholder task',
        'estimatedMinutes' => 10,
    ],
    'flexibleItem' => 'event', // Should become ItemType::EVENT
]);

$isEnum3 = $container3->flexibleItem instanceof ItemType;
echo "Input: flexibleItem = 'event' (should resolve to ItemType::EVENT)\n";
echo 'Result: flexibleItem is '.get_debug_type($container3->flexibleItem)."\n";
if ($isEnum3) {
    echo "  - Enum value: {$container3->flexibleItem->value}\n";
    echo "✓ ItemType enum correctly resolved\n\n";
} else {
    echo '✗ FAILED: Expected ItemType enum, got '.gettype($container3->flexibleItem)."\n\n";
}

echo "--- Test 4d: TaskInfo in complex union ---\n";
$container4 = ItemContainer::fromArray([
    'id' => 4,
    'item' => [
        'name' => 'Some event',
        'location' => 'Somewhere',
    ],
    'flexibleItem' => [
        'title' => 'Important Task',
        'estimatedMinutes' => 120,
    ],
]);

$isTaskInfo4 = $container4->flexibleItem instanceof TaskInfo;
echo "Input: flexibleItem = TaskInfo data (title, estimatedMinutes)\n";
echo 'Result: flexibleItem is '.get_debug_type($container4->flexibleItem)."\n";
if ($container4->flexibleItem instanceof TaskInfo) {
    echo "  - title: {$container4->flexibleItem->title}\n";
    echo "  - estimatedMinutes: {$container4->flexibleItem->estimatedMinutes}\n";
    echo "✓ TaskInfo correctly resolved\n\n";
} elseif ($container4->flexibleItem instanceof EventInfo) {
    echo "  - (wrongly created as EventInfo)\n";
    echo "✗ FAILED: Expected TaskInfo, got EventInfo\n\n";
} else {
    echo "✗ FAILED: Expected TaskInfo\n\n";
}

echo "=== Multi-DataModel Union Tests Complete ===\n";

// ============================================
// Test 5: Multiple Enums in Union Type (Enum1|Enum2)
// ============================================
echo "\n\n=== Test 5: Multiple Enums in Union Type (Enum1|Enum2) ===\n\n";

enum StatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
}

enum CategoryEnum: string
{
    case WORK = 'work';
    case PERSONAL = 'personal';
    case URGENT = 'urgent';
}

// DataModel with multiple enums in union
class EnumUnionContainer extends DataModel
{
    #[Desc('Item identifier')]
    public int $id;

    #[Desc('Status or category - can be either enum')]
    public StatusEnum|CategoryEnum $statusOrCategory;
}

echo "--- Schema for EnumUnionContainer ---\n";
$enumUnionSchema = EnumUnionContainer::generateSchema();
echo json_encode($enumUnionSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

echo "--- Test 5a: StatusEnum value ---\n";
$enumContainer1 = EnumUnionContainer::fromArray([
    'id' => 1,
    'statusOrCategory' => 'active', // Should resolve to StatusEnum::ACTIVE
]);

$isStatusEnum = $enumContainer1->statusOrCategory instanceof StatusEnum;
echo "Input: statusOrCategory = 'active' (should resolve to StatusEnum::ACTIVE)\n";
echo 'Result: statusOrCategory is '.get_debug_type($enumContainer1->statusOrCategory)."\n";
if ($enumContainer1->statusOrCategory instanceof StatusEnum) {
    echo "  - Enum value: {$enumContainer1->statusOrCategory->value}\n";
    echo "✓ StatusEnum correctly resolved\n\n";
} elseif ($enumContainer1->statusOrCategory instanceof CategoryEnum) {
    echo "  - (wrongly resolved as CategoryEnum)\n";
    echo "✗ FAILED: Expected StatusEnum\n\n";
} else {
    echo '✗ FAILED: Expected StatusEnum, got '.gettype($enumContainer1->statusOrCategory)."\n\n";
}

echo "--- Test 5b: CategoryEnum value ---\n";
$enumContainer2 = EnumUnionContainer::fromArray([
    'id' => 2,
    'statusOrCategory' => 'work', // Should resolve to CategoryEnum::WORK
]);

$isCategoryEnum = $enumContainer2->statusOrCategory instanceof CategoryEnum;
echo "Input: statusOrCategory = 'work' (should resolve to CategoryEnum::WORK)\n";
echo 'Result: statusOrCategory is '.get_debug_type($enumContainer2->statusOrCategory)."\n";
if ($enumContainer2->statusOrCategory instanceof CategoryEnum) {
    echo "  - Enum value: {$enumContainer2->statusOrCategory->value}\n";
    echo "✓ CategoryEnum correctly resolved\n\n";
} elseif ($enumContainer2->statusOrCategory instanceof StatusEnum) {
    echo "  - (wrongly resolved as StatusEnum)\n";
    echo "✗ FAILED: Expected CategoryEnum\n\n";
} else {
    echo '✗ FAILED: Expected CategoryEnum, got '.gettype($enumContainer2->statusOrCategory)."\n\n";
}

echo "--- Test 5c: Ambiguous value (exists in neither) ---\n";
echo "Note: When property type is strictly Enum1|Enum2 (no string fallback),\n";
echo "      PHP will throw TypeError if no enum matches - this is expected behavior.\n";
try {
    $enumContainer3 = EnumUnionContainer::fromArray([
        'id' => 3,
        'statusOrCategory' => 'unknown_value', // Doesn't exist in either enum
    ]);
    echo "✗ UNEXPECTED: Should have thrown TypeError\n\n";
} catch (TypeError $e) {
    echo "✓ Correctly throws TypeError for invalid enum value (expected behavior)\n\n";
}

echo "=== Enum Union Tests Complete ===\n";

// ============================================
// Test 6: Complex Union (DataModel1|DataModel2|Enum1|Enum2)
// ============================================
echo "\n\n=== Test 6: Complex Union (DataModel1|DataModel2|Enum1|Enum2) ===\n\n";

class ComplexUnionContainer extends DataModel
{
    #[Desc('Item identifier')]
    public int $id;

    #[Desc('Complex item - can be TaskInfo, EventInfo, StatusEnum, or CategoryEnum')]
    public TaskInfo|EventInfo|StatusEnum|CategoryEnum $complexItem;
}

echo "--- Schema for ComplexUnionContainer ---\n";
$complexSchema = ComplexUnionContainer::generateSchema();
echo json_encode($complexSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

echo "--- Test 6a: TaskInfo (first DataModel) ---\n";
$complex1 = ComplexUnionContainer::fromArray([
    'id' => 1,
    'complexItem' => [
        'title' => 'Write tests',
        'estimatedMinutes' => 60,
    ],
]);

echo "Input: complexItem = TaskInfo data (title, estimatedMinutes)\n";
echo 'Result: complexItem is '.get_debug_type($complex1->complexItem)."\n";
if ($complex1->complexItem instanceof TaskInfo) {
    echo "  - title: {$complex1->complexItem->title}\n";
    echo "  - estimatedMinutes: {$complex1->complexItem->estimatedMinutes}\n";
    echo "✓ TaskInfo correctly resolved\n\n";
} else {
    echo "✗ FAILED: Expected TaskInfo\n\n";
}

echo "--- Test 6b: EventInfo (second DataModel) ---\n";
$complex2 = ComplexUnionContainer::fromArray([
    'id' => 2,
    'complexItem' => [
        'name' => 'Sprint Planning',
        'location' => 'Meeting Room B',
    ],
]);

echo "Input: complexItem = EventInfo data (name, location)\n";
echo 'Result: complexItem is '.get_debug_type($complex2->complexItem)."\n";
if ($complex2->complexItem instanceof EventInfo) {
    echo "  - name: {$complex2->complexItem->name}\n";
    echo "  - location: {$complex2->complexItem->location}\n";
    echo "✓ EventInfo correctly resolved\n\n";
} else {
    echo "✗ FAILED: Expected EventInfo\n\n";
}

echo "--- Test 6c: StatusEnum (first Enum) ---\n";
$complex3 = ComplexUnionContainer::fromArray([
    'id' => 3,
    'complexItem' => 'pending', // StatusEnum::PENDING
]);

echo "Input: complexItem = 'pending' (should resolve to StatusEnum::PENDING)\n";
echo 'Result: complexItem is '.get_debug_type($complex3->complexItem)."\n";
if ($complex3->complexItem instanceof StatusEnum) {
    echo "  - Enum value: {$complex3->complexItem->value}\n";
    echo "✓ StatusEnum correctly resolved\n\n";
} elseif ($complex3->complexItem instanceof CategoryEnum) {
    echo "✗ FAILED: Expected StatusEnum, got CategoryEnum\n\n";
} else {
    echo '✗ FAILED: Expected StatusEnum, got '.get_debug_type($complex3->complexItem)."\n\n";
}

echo "--- Test 6d: CategoryEnum (second Enum) ---\n";
$complex4 = ComplexUnionContainer::fromArray([
    'id' => 4,
    'complexItem' => 'personal', // CategoryEnum::PERSONAL
]);

echo "Input: complexItem = 'personal' (should resolve to CategoryEnum::PERSONAL)\n";
echo 'Result: complexItem is '.get_debug_type($complex4->complexItem)."\n";
if ($complex4->complexItem instanceof CategoryEnum) {
    echo "  - Enum value: {$complex4->complexItem->value}\n";
    echo "✓ CategoryEnum correctly resolved\n\n";
} elseif ($complex4->complexItem instanceof StatusEnum) {
    echo "✗ FAILED: Expected CategoryEnum, got StatusEnum\n\n";
} else {
    echo '✗ FAILED: Expected CategoryEnum, got '.get_debug_type($complex4->complexItem)."\n\n";
}

echo "=== Complex Union Tests Complete ===\n";
echo "\n=== ALL DATAMODEL UNION TYPE TESTS FINISHED ===\n";
