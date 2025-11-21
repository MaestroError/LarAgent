<?php

require_once __DIR__ . '/../vendor/autoload.php';

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

// --- Definitions ---

enum UserRole: string {
    case ADMIN = 'admin';
    case EDITOR = 'editor';
    case VIEWER = 'viewer';
}

enum NotificationType {
    case EMAIL;
    case SMS;
    case PUSH;
}

class Address extends DataModel {
    public function __construct(
        #[Desc("The street address")]
        public string $street,

        #[Desc("The city name")]
        public string $city,

        #[Desc("The postal code")]
        public ?string $zipCode = null // Optional
    ) {}
}

class UserPreferences extends DataModel {
    private string $apiKey;

    #[Desc("Receive marketing emails")]
    public bool $marketingEmails = false;

    #[Desc("Preferred notification types")]
    public array $notifications = []; // Array of NotificationType names or values

    public function __construct(
        string $apiKey
    ) {
        $this->apiKey = $apiKey;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}

class UserProfile extends DataModel {
    #[Desc("The unique identifier for the user")]
    public int $id;

    #[Desc("The user's full name")]
    public string $name;

    #[Desc("The user's role in the system")]
    public UserRole $role;

    #[Desc("The user's physical address")]
    public Address $address;

    #[Desc("User settings and preferences")]
    public UserPreferences $preferences;

    #[Desc("List of tags associated with the user")]
    public array $tags = [];
}

// --- Execution ---

echo "--- Complex DataModel Manual Test ---\n\n";

// 1. Define input data (simulating API response or DB record)
$inputData = [
    'id' => 12345,
    'name' => 'Alice Wonderland',
    'role' => 'admin', // Should cast to UserRole::ADMIN
    'address' => [
        'street' => '123 Rabbit Hole Ln',
        'city' => 'Wonderland',
        // zipCode is optional/nullable
    ],
    'preferences' => [
        'marketingEmails' => true,
        'notifications' => ['EMAIL', 'PUSH'], // Just arrays for now, DataModel doesn't auto-cast array items to Enums yet unless handled specifically, but let's see how it behaves with simple arrays.
        'apiKey' => 'secret_12345',
    ],
    'tags' => ['vip', 'beta-tester'],
];

echo "1. Input Data:\n";
print_r($inputData);
echo "\n";

// 2. Create and Fill Model
try {
    $user = UserProfile::fromArray($inputData);
    echo "2. Model Created Successfully!\n";
    echo "   Name: " . $user->name . "\n";
    echo "   Role: " . $user->role->value . "\n";
    echo "   City: " . $user->address->city . "\n";
    echo "   Marketing: " . ($user->preferences->marketingEmails ? 'Yes' : 'No') . "\n";
    echo "   Private API Key: " . $user->preferences->getApiKey() . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// 3. Convert back to Array
$outputArray = $user->toArray();
echo "3. Converted back to Array (toArray):\n";
print_r($outputArray);
echo "\n";

// 4. Generate Schema
$schema = $user->toSchema();
echo "4. Generated OpenAPI Schema (toSchema):\n";
echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";

// Validation check
if ($schema['properties']['name']['description'] === "The user's full name") {
    echo "\n[PASS] Schema contains descriptions.\n";
} else {
    echo "\n[FAIL] Schema missing descriptions.\n";
}

if (isset($schema['properties']['role']['enum'])) {
    echo "[PASS] Schema contains enums.\n";
} else {
    echo "[FAIL] Schema missing enums.\n";
}

if (!empty($schema['required'])) {
    echo "[PASS] Schema contains required fields.\n";
} else {
    echo "[FAIL] Schema missing required fields.\n";
}
