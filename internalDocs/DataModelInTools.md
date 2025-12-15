# DataModels in Tools (Attribute-Based Tools)

LarAgent allows you to define tools using PHP method attributes. When combined with DataModels, you get type-safe tool parameters with automatic schema generation and value conversion.

## Overview

Attribute-based tools use the `#[Tool]` attribute to mark methods as tools available to the AI. Parameters can be:

- Primitive types (string, int, float, bool)
- Enums (for constrained string values)
- DataModels (for complex structured parameters)
- Union types (string|int, DataModel|array)

## Basic Attribute Tool

```php
<?php

namespace App\Agents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;

class MyAgent extends Agent
{
    public function instructions(): string
    {
        return 'You are a helpful assistant with access to tools.';
    }

    #[Tool('Get current weather for a location')]
    public function getWeather(string $location): string
    {
        return "Weather in {$location}: Sunny, 22°C";
    }
}
```

## Using DataModels in Tools

### Define a DataModel

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class TaskInput extends DataModel
{
    #[Desc('Title of the task')]
    public string $title;

    #[Desc('Description of what needs to be done')]
    public string $description;

    #[Desc('Priority: low, medium, or high')]
    public string $priority = 'medium';

    #[Desc('Due date in YYYY-MM-DD format')]
    public ?string $dueDate = null;
}
```

### Use in Tool Method

```php
<?php

namespace App\Agents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\DataModels\TaskInput;

class TaskAgent extends Agent
{
    public function instructions(): string
    {
        return 'You are a task management assistant.';
    }

    #[Tool('Create a new task')]
    public function createTask(TaskInput $task): string
    {
        // $task is automatically converted from array to TaskInput instance
        return "Task created: {$task->title} (Priority: {$task->priority})";
    }
}
```

When the AI calls this tool, LarAgent:
1. Generates a JSON schema from `TaskInput`
2. Sends it to the AI as the tool's input schema
3. Receives the AI's response as an array
4. Automatically converts the array to a `TaskInput` instance
5. Calls your method with the typed object

## Parameter Descriptions

The `#[Tool]` attribute accepts parameter descriptions:

```php
#[Tool(
    'Search for products',
    parameterDescriptions: [
        'query' => 'Search query string',
        'category' => 'Product category to filter by',
        'maxPrice' => 'Maximum price in USD'
    ]
)]
public function searchProducts(string $query, ?string $category = null, ?float $maxPrice = null): array
{
    // Search logic
}
```

For DataModel parameters, descriptions come from the `#[Desc]` attribute on properties.

## Using Enums

### Define an Enum

```php
<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
```

### Use in Tool

```php
use App\Enums\Priority;

#[Tool('Set task priority')]
public function setPriority(string $taskId, Priority $priority): string
{
    // $priority is automatically converted from string to Priority enum
    return "Task {$taskId} priority set to {$priority->value}";
}
```

The generated schema includes enum values:

```json
{
    "priority": {
        "type": "string",
        "enum": ["low", "medium", "high"]
    }
}
```

## Complex Examples

### Multiple DataModel Parameters

```php
class Address extends DataModel
{
    #[Desc('Street address')]
    public string $street;

    #[Desc('City name')]
    public string $city;

    #[Desc('ZIP code')]
    public string $zipCode;
}

class ContactInfo extends DataModel
{
    #[Desc('Email address')]
    public string $email;

    #[Desc('Phone number')]
    public ?string $phone = null;
}

class CustomerAgent extends Agent
{
    #[Tool('Register a new customer with address and contact info')]
    public function registerCustomer(
        string $name,
        Address $address,
        ContactInfo $contact
    ): string {
        return "Customer {$name} registered at {$address->city}";
    }
}
```

### DataModel with Enum Property

```php
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
}

class OrderUpdate extends DataModel
{
    #[Desc('Order ID')]
    public string $orderId;

    #[Desc('New status for the order')]
    public OrderStatus $status;

    #[Desc('Optional notes about the update')]
    public ?string $notes = null;
}

class OrderAgent extends Agent
{
    #[Tool('Update order status')]
    public function updateOrderStatus(OrderUpdate $update): string
    {
        // $update->status is already an OrderStatus enum
        return "Order {$update->orderId} updated to {$update->status->value}";
    }
}
```

### Union Types

```php
class ReportFilter extends DataModel
{
    #[Desc('Start date')]
    public string $startDate;

    #[Desc('End date')]
    public string $endDate;
}

class ReportAgent extends Agent
{
    #[Tool('Generate a report')]
    public function generateReport(
        string $type,
        string|ReportFilter $dateRange  // Can be "last_week" or a filter object
    ): string {
        if (is_string($dateRange)) {
            return "Generating {$type} report for {$dateRange}";
        }
        return "Generating {$type} report from {$dateRange->startDate} to {$dateRange->endDate}";
    }
}
```

## How Schema Generation Works

When LarAgent processes an attribute tool, it:

1. **Inspects method parameters** using reflection
2. **Generates schemas** based on types:
   - `string` → `{"type": "string"}`
   - `int` → `{"type": "integer"}`
   - `float` → `{"type": "number"}`
   - `bool` → `{"type": "boolean"}`
   - `array` → `{"type": "array"}`
   - `Enum` → `{"type": "string", "enum": [values]}`
   - `DataModel` → Full object schema with properties

3. **Marks required parameters** (non-optional without defaults)

4. **Stores type mappings** for runtime conversion

### Example Generated Schema

For a tool like:

```php
#[Tool('Create a project')]
public function createProject(ProjectInput $project, Priority $priority): string
```

The generated schema is:

```json
{
    "type": "function",
    "function": {
        "name": "createProject",
        "description": "Create a project",
        "parameters": {
            "type": "object",
            "properties": {
                "project": {
                    "type": "object",
                    "properties": {
                        "name": {
                            "type": "string",
                            "description": "Project name"
                        },
                        "budget": {
                            "type": "number",
                            "description": "Project budget"
                        }
                    },
                    "required": ["name", "budget"]
                },
                "priority": {
                    "type": "string",
                    "enum": ["low", "medium", "high"]
                }
            },
            "required": ["project", "priority"]
        }
    }
}
```

## Runtime Value Conversion

When the AI calls a tool, LarAgent:

1. **Receives JSON arguments** from the AI:
   ```json
   {
       "project": {"name": "New Website", "budget": 5000},
       "priority": "high"
   }
   ```

2. **Converts to typed values**:
   - `project` array → `ProjectInput` instance
   - `"high"` string → `Priority::High` enum

3. **Calls your method** with typed parameters

This happens automatically through the `UnionTypeResolver` utility.

## Combining with Traditional Tools

You can mix attribute tools with traditional tool registration:

```php
class MyAgent extends Agent
{
    // Attribute-based tool
    #[Tool('Get user info')]
    public function getUserInfo(string $userId): array
    {
        return ['id' => $userId, 'name' => 'John'];
    }

    // Traditional tool registration
    public function registerTools(): array
    {
        return [
            Tool::create('calculate', 'Perform calculation')
                ->addProperty('expression', 'string', 'Math expression')
                ->setRequired('expression')
                ->setCallback(fn($expr) => eval("return {$expr};")),
        ];
    }
}
```

## Optional Parameters

```php
#[Tool('Search with optional filters')]
public function search(
    string $query,                    // Required
    ?string $category = null,         // Optional
    int $limit = 10                   // Optional with default
): array {
    // ...
}
```

Schema marks only `query` as required.

## Static Methods

Attribute tools can be static:

```php
#[Tool('Get system status')]
public static function getSystemStatus(): array
{
    return [
        'status' => 'operational',
        'uptime' => '99.9%'
    ];
}
```

## Best Practices

### 1. Use Descriptive DataModel Properties

```php
class FlightSearch extends DataModel
{
    #[Desc('Departure airport code (3 letters), e.g., JFK, LAX')]
    public string $from;

    #[Desc('Arrival airport code (3 letters), e.g., SFO, ORD')]
    public string $to;

    #[Desc('Departure date in YYYY-MM-DD format')]
    public string $date;

    #[Desc('Number of passengers, 1-9')]
    public int $passengers = 1;
}
```

### 2. Use Enums for Constrained Values

```php
enum TripType: string
{
    case OneWay = 'one_way';
    case RoundTrip = 'round_trip';
}

#[Tool('Book a flight')]
public function bookFlight(FlightSearch $search, TripType $type): string
```

### 3. Keep DataModels Focused

```php
// Good: Focused DataModel
class EmailInput extends DataModel
{
    #[Desc('Recipient email')]
    public string $to;

    #[Desc('Email subject')]
    public string $subject;

    #[Desc('Email body')]
    public string $body;
}

// Avoid: Kitchen-sink DataModel
class EmailInput extends DataModel
{
    // Too many unrelated fields...
}
```

### 4. Provide Tool Descriptions

```php
#[Tool('Send an email to a recipient. Use this when the user wants to send messages.')]
public function sendEmail(EmailInput $email): string
```

### 5. Handle Errors Gracefully

```php
#[Tool('Delete a file')]
public function deleteFile(string $path): string
{
    if (!file_exists($path)) {
        return "Error: File not found at {$path}";
    }
    
    unlink($path);
    return "File deleted: {$path}";
}
```

## Performance Note

DataModel type information is cached using the `UsesCachedReflection` trait, so repeated tool calls don't incur reflection overhead after the first call.
