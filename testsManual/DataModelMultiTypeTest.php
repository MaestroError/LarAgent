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
        $base_dir = __DIR__ . '/../src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
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
echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

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
echo "Result: userId is " . gettype($settings1->userId) . ": {$settings1->userId}\n";
echo "        enabled is " . gettype($settings1->enabled) . ": " . var_export($settings1->enabled, true) . "\n";
echo "        contact is " . get_class($settings1->contact) . ": {$settings1->contact->value}\n";
echo "        priority is " . get_debug_type($settings1->priority) . ": ";
if ($settings1->priority instanceof Priority) {
    echo $settings1->priority->value . "\n";
} else {
    echo "{$settings1->priority}\n";
}
echo "        metadata is " . gettype($settings1->metadata) . ": {$settings1->metadata}\n\n";

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
echo "Result: userId is " . gettype($settings2->userId) . ": {$settings2->userId}\n";
echo "        enabled is " . get_class($settings2->enabled) . ": {$settings2->enabled->value}\n";
echo "        contact is " . get_class($settings2->contact) . "\n";
echo "          - email: {$settings2->contact->email}\n";
echo "          - phone: {$settings2->contact->phone}\n";
echo "        priority is " . get_debug_type($settings2->priority) . ": ";
if ($settings2->priority instanceof Priority) {
    echo $settings2->priority->value . "\n";
} else {
    echo "{$settings2->priority}\n";
}
echo "        metadata is " . gettype($settings2->metadata) . ": " . json_encode($settings2->metadata) . "\n\n";

// Example 3: Serialization back to array
echo "--- Example 3: Serialization ---\n";
$array = $settings2->toArray();
echo "Serialized back to array:\n";
echo json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// Demonstrate oneOf in schema
echo "--- Schema Details: oneOf Examples ---\n\n";

echo "1. userId (string|int):\n";
echo json_encode($schema['properties']['userId'], JSON_PRETTY_PRINT) . "\n\n";

echo "2. enabled (bool|NotificationChannel):\n";
echo json_encode($schema['properties']['enabled'], JSON_PRETTY_PRINT) . "\n\n";

echo "3. contact (NotificationChannel|ContactInfo):\n";
echo json_encode($schema['properties']['contact'], JSON_PRETTY_PRINT) . "\n\n";

echo "4. metadata (string|int|array):\n";
echo json_encode($schema['properties']['metadata'], JSON_PRETTY_PRINT) . "\n\n";

echo "=== Demo Complete ===\n";
