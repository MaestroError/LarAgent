# Structured Output with DataModels

Structured Output allows you to define a schema for the AI's response, ensuring it returns data in a specific format. LarAgent supports using DataModels to define these schemas, providing type safety and automatic schema generation.

## Overview

Instead of manually writing JSON schemas, you can use DataModel classes to:

1. Define response structures with PHP types
2. Automatically generate OpenAPI-compatible schemas
3. Get type-safe responses from AI

## Basic Usage

### Define a DataModel for Response

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class WeatherInfo extends DataModel
{
    #[Desc('The city name')]
    public string $city;

    #[Desc('Temperature in celsius')]
    public float $temperature;

    #[Desc('Weather condition (e.g., sunny, cloudy, rainy)')]
    public string $condition;

    #[Desc('Humidity percentage')]
    public ?int $humidity = null;
}
```

### Use in Agent

#### Option 1: Via Property (Class Name)

```php
<?php

namespace App\Agents;

use LarAgent\Agent;
use App\DataModels\WeatherInfo;

class WeatherAgent extends Agent
{
    protected $model = 'gpt-4o-mini';
    
    // Set response schema to DataModel class name
    protected $responseSchema = WeatherInfo::class;

    public function instructions(): string
    {
        return 'You are a weather information assistant. Provide weather data based on user queries.';
    }
}

// Usage
$agent = WeatherAgent::make();
$response = $agent->respond('What is the weather in Paris?');
// $response is an array matching WeatherInfo schema:
// ['city' => 'Paris', 'temperature' => 15.5, 'condition' => 'cloudy', 'humidity' => 75]
```

#### Option 2: Via Property (Instance)

```php
class WeatherAgent extends Agent
{
    public function __construct($key, bool $usesUserId = false, ?string $group = null)
    {
        parent::__construct($key, $usesUserId, $group);
        
        // Set response schema to DataModel instance
        $this->responseSchema = new WeatherInfo();
    }
}
```

#### Option 3: Override structuredOutput() Method

```php
class WeatherAgent extends Agent
{
    public function structuredOutput(): ?array
    {
        return WeatherInfo::generateSchema();
    }
}
```

#### Option 4: Fluent API

```php
$agent = WeatherAgent::make()
    ->responseSchema(WeatherInfo::class);

// Or with an instance
$agent = WeatherAgent::make()
    ->responseSchema(new WeatherInfo());

// Or with an array schema (backward compatible)
$agent = WeatherAgent::make()
    ->responseSchema([
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string'],
            'temperature' => ['type' => 'number'],
        ],
        'required' => ['city', 'temperature']
    ]);
```

## Schema Generation

### Generated Schema

When you use `WeatherInfo::generateSchema()` or `$weatherInfo->toSchema()`, it produces:

```json
{
    "type": "object",
    "properties": {
        "city": {
            "type": "string",
            "description": "The city name"
        },
        "temperature": {
            "type": "number",
            "description": "Temperature in celsius"
        },
        "condition": {
            "type": "string",
            "description": "Weather condition (e.g., sunny, cloudy, rainy)"
        },
        "humidity": {
            "type": "integer",
            "description": "Humidity percentage"
        }
    },
    "required": ["city", "temperature", "condition"]
}
```

### The #[Desc] Attribute

The `#[Desc]` attribute provides descriptions for the AI:

```php
use LarAgent\Attributes\Desc;

class Product extends DataModel
{
    #[Desc('The product name, max 100 characters')]
    public string $name;

    #[Desc('Product price in USD, must be positive')]
    public float $price;

    #[Desc('Product category: electronics, clothing, food, or other')]
    public string $category;
}
```

These descriptions help the AI understand the expected format and constraints.

## Complex Schemas

### Nested DataModels

```php
class Address extends DataModel
{
    #[Desc('Street address')]
    public string $street;

    #[Desc('City name')]
    public string $city;

    #[Desc('Country name')]
    public string $country;
}

class Person extends DataModel
{
    #[Desc('Full name of the person')]
    public string $name;

    #[Desc('Person address')]
    public Address $address;
}
```

### Arrays

```php
class Report extends DataModel
{
    #[Desc('Report title')]
    public string $title;

    #[Desc('List of findings')]
    public array $findings;  // Will be typed as array in schema

    #[Desc('Report date')]
    public ?string $date = null;
}
```

### Nullable Properties

```php
class UserProfile extends DataModel
{
    #[Desc('Username')]
    public string $username;

    #[Desc('Optional biography')]
    public ?string $bio = null;  // Marked as optional in schema

    #[Desc('Age (optional)')]
    public ?int $age = null;
}
```

### Enums

```php
enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}

class Task extends DataModel
{
    #[Desc('Task title')]
    public string $title;

    #[Desc('Task priority level')]
    public Priority $priority;  // Generates enum values in schema
}
```

### Constructor Promotion

```php
class SearchResult extends DataModel
{
    public function __construct(
        #[Desc('The item title')]
        public string $title,

        #[Desc('Relevance score from 0 to 1')]
        public float $score,

        #[Desc('Result URL')]
        public ?string $url = null,
    ) {}
}
```

## Response Handling

When structured output is enabled, the AI response is:

1. **Returned as array** - The response is automatically parsed:
   ```php
   $response = $agent->respond('Get weather for London');
   // $response is array: ['city' => 'London', 'temperature' => 12.5, ...]
   ```

2. **Type-safe conversion** (optional) - Convert to DataModel:
   ```php
   $response = $agent->respond('Get weather for London');
   $weather = WeatherInfo::fromArray($response);
   echo $weather->city;  // 'London'
   echo $weather->temperature;  // 12.5
   ```

## How It Works with OpenAI

LarAgent wraps your schema in OpenAI's required format:

```json
{
    "response_format": {
        "type": "json_schema",
        "json_schema": {
            "name": "weather_info",
            "schema": { /* your schema */ },
            "strict": true
        }
    }
}
```

The driver automatically:
- Generates a name from the schema
- Sets `strict: true` for enforcement
- Adds `additionalProperties: false` to all objects

## Best Practices

### 1. Use Descriptive Names and Descriptions

```php
class CustomerFeedback extends DataModel
{
    #[Desc('Customer satisfaction rating from 1 (very unsatisfied) to 5 (very satisfied)')]
    public int $rating;

    #[Desc('Detailed feedback comment explaining the rating')]
    public string $comment;

    #[Desc('Whether the customer would recommend the product')]
    public bool $wouldRecommend;
}
```

### 2. Keep Schemas Simple

Complex nested schemas can confuse the AI. Start simple:

```php
// Good: Simple, clear structure
class Summary extends DataModel
{
    #[Desc('Main summary points')]
    public array $points;

    #[Desc('Overall sentiment: positive, negative, or neutral')]
    public string $sentiment;
}

// Avoid: Deeply nested structures
class ComplexReport extends DataModel
{
    public Section $section1;
    public Section $section2;
    // ... many nested levels
}
```

### 3. Use Enums for Constrained Values

```php
enum Sentiment: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
}

class Analysis extends DataModel
{
    #[Desc('Sentiment classification')]
    public Sentiment $sentiment;
}
```

### 4. Provide Examples in Descriptions

```php
class ContactInfo extends DataModel
{
    #[Desc('Email address, e.g., user@example.com')]
    public string $email;

    #[Desc('Phone number in international format, e.g., +1-555-123-4567')]
    public ?string $phone = null;
}
```

## Streaming with Structured Output

Structured output works with streaming:

```php
$agent = MyAgent::make()->responseSchema(WeatherInfo::class);

$stream = $agent->respondStreamed('Get weather for Tokyo');

foreach ($stream as $chunk) {
    // Final chunk contains the structured response
    if (is_array($chunk)) {
        $weather = WeatherInfo::fromArray($chunk);
        echo $weather->city;
    }
}
```

## Provider Support

| Provider | Structured Output Support |
|----------|--------------------------|
| OpenAI | ✅ Full support |
| Groq | ✅ Full support |
| Gemini (OpenAI-compatible) | ✅ Full support |
| Claude/Anthropic | ❌ Not supported (throws exception) |

For Claude, use tools instead of structured output, or instruct the model to return JSON in its response.

## Example: Multi-Step Extraction

```php
// Define models for extraction
class ExtractedEntity extends DataModel
{
    #[Desc('Entity name')]
    public string $name;

    #[Desc('Entity type: person, organization, location, or date')]
    public string $type;

    #[Desc('Confidence score from 0 to 1')]
    public float $confidence;
}

class ExtractionResult extends DataModel
{
    #[Desc('List of extracted entities')]
    public array $entities;

    #[Desc('Original text summarized')]
    public string $summary;
}

// Use in agent
class EntityExtractor extends Agent
{
    protected $responseSchema = ExtractionResult::class;

    public function instructions(): string
    {
        return 'Extract named entities from the provided text. Identify people, organizations, locations, and dates.';
    }
}

// Usage
$extractor = EntityExtractor::make();
$result = $extractor->respond('John Smith met with Apple Inc. in San Francisco on January 15, 2024.');

// Result:
// [
//     'entities' => [
//         ['name' => 'John Smith', 'type' => 'person', 'confidence' => 0.95],
//         ['name' => 'Apple Inc.', 'type' => 'organization', 'confidence' => 0.98],
//         ['name' => 'San Francisco', 'type' => 'location', 'confidence' => 0.99],
//         ['name' => 'January 15, 2024', 'type' => 'date', 'confidence' => 0.97],
//     ],
//     'summary' => 'Meeting between John Smith and Apple Inc. in San Francisco'
// ]
```
