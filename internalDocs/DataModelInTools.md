# Using DataModel in Attribute-Based Tools

This guide explains how to use DataModel classes as parameters in attribute-based tools in LarAgent. DataModels provide type safety, automatic schema generation, and seamless conversion between arrays and objects.

## Overview

When you define tools using the `#[Tool]` attribute, LarAgent automatically:
1. Detects DataModel type hints on parameters
2. Generates JSON schemas for the LLM
3. Converts array responses from the LLM back to DataModel instances

## Basic Usage

### Defining a DataModel

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class Address extends DataModel
{
    #[Desc('Street address')]
    public string $street;
    
    #[Desc('City name')]
    public string $city;
    
    #[Desc('State or province')]
    public string $state;
    
    #[Desc('Postal/ZIP code')]
    public string $postalCode;
    
    #[Desc('Country code (ISO 3166-1 alpha-2)')]
    public string $country = 'US';
}
```

### Using DataModel as Tool Parameter

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\DataModels\Address;

class DeliveryAgent extends Agent
{
    protected $instructions = 'You help customers with delivery inquiries.';
    
    #[Tool('Calculate shipping cost to an address')]
    public function calculateShipping(Address $address, float $weight): string
    {
        // $address is automatically hydrated as an Address DataModel instance
        $zone = $this->getShippingZone($address->country, $address->state);
        $cost = $this->calculateCost($zone, $weight);
        
        return "Shipping to {$address->city}, {$address->state} costs \${$cost}";
    }
    
    #[Tool('Validate a delivery address')]
    public function validateAddress(Address $address): array
    {
        // Work with the DataModel directly
        return [
            'valid' => $this->isValidAddress($address),
            'formatted' => "{$address->street}, {$address->city}, {$address->state} {$address->postalCode}",
        ];
    }
}
```

### Generated Schema

LarAgent automatically generates this schema for the LLM:

```json
{
  "name": "calculateShipping",
  "description": "Calculate shipping cost to an address",
  "parameters": {
    "type": "object",
    "properties": {
      "address": {
        "type": "object",
        "properties": {
          "street": {
            "type": "string",
            "description": "Street address"
          },
          "city": {
            "type": "string",
            "description": "City name"
          },
          "state": {
            "type": "string",
            "description": "State or province"
          },
          "postalCode": {
            "type": "string",
            "description": "Postal/ZIP code"
          },
          "country": {
            "type": "string",
            "description": "Country code (ISO 3166-1 alpha-2)"
          }
        },
        "required": ["street", "city", "state", "postalCode"]
      },
      "weight": {
        "type": "number",
        "description": ""
      }
    },
    "required": ["address", "weight"]
  }
}
```

## Adding Descriptions to Tool Parameters

Use the `#[Tool]` attribute's named parameters to add descriptions:

```php
#[Tool(
    description: 'Calculate shipping cost to an address',
    parameterDescriptions: [
        'address' => 'The delivery destination address',
        'weight' => 'Package weight in kilograms',
    ]
)]
public function calculateShipping(Address $address, float $weight): string
{
    // ...
}
```

## Nested DataModels

DataModels can contain other DataModels:

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class ContactInfo extends DataModel
{
    #[Desc('Email address')]
    public string $email;
    
    #[Desc('Phone number')]
    public ?string $phone = null;
}

class Customer extends DataModel
{
    #[Desc('Customer full name')]
    public string $name;
    
    #[Desc('Contact information')]
    public ContactInfo $contact;
    
    #[Desc('Shipping address')]
    public Address $shippingAddress;
    
    #[Desc('Billing address (if different from shipping)')]
    public ?Address $billingAddress = null;
}
```

```php
#[Tool('Create a new customer order')]
public function createOrder(Customer $customer, array $items): string
{
    // $customer->contact is a ContactInfo instance
    // $customer->shippingAddress is an Address instance
    $email = $customer->contact->email;
    $city = $customer->shippingAddress->city;
    
    return "Order created for {$customer->name} in {$city}";
}
```

## DataModels with Enums

DataModels can use PHP enums for constrained values:

```php
<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';
}

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;
use App\Enums\Priority;
use App\Enums\TicketStatus;

class SupportTicket extends DataModel
{
    #[Desc('Brief description of the issue')]
    public string $title;
    
    #[Desc('Detailed description')]
    public string $description;
    
    #[Desc('Priority level')]
    public Priority $priority = Priority::Medium;
    
    #[Desc('Current status')]
    public TicketStatus $status = TicketStatus::Open;
}
```

```php
#[Tool('Create a support ticket')]
public function createTicket(SupportTicket $ticket): string
{
    // $ticket->priority is a Priority enum instance
    if ($ticket->priority === Priority::Urgent) {
        $this->notifyOnCall($ticket);
    }
    
    return "Ticket created: {$ticket->title} [{$ticket->priority->value}]";
}
```

The generated schema will include enum constraints:

```json
{
  "priority": {
    "type": "string",
    "enum": ["low", "medium", "high", "urgent"],
    "description": "Priority level"
  }
}
```

## Arrays of DataModels

LarAgent provides `DataModelArray` for working with arrays of DataModel instances. The main feature is **polymorphic (multitype) arrays** - a single array that can contain different DataModel types, resolved automatically using a discriminator field.

### Single-Type Arrays

For arrays containing only one type of DataModel:

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class LineItem extends DataModel
{
    #[Desc('Product SKU or ID')]
    public string $productId;
    
    #[Desc('Quantity to order')]
    public int $quantity;
    
    #[Desc('Unit price')]
    public float $price;
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class LineItemArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        // Single type - just return array with the class
        return [LineItem::class];
    }
    
    // No discriminator needed for single-type arrays
    
    public function getTotal(): float
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->quantity * $item->price;
        }
        return $total;
    }
}
```

```php
#[Tool('Process an order with multiple items')]
public function processOrder(Customer $customer, LineItemArray $items): string
{
    $total = $items->getTotal();
    $itemCount = $items->count();
    
    return "Processing order for {$customer->name}: {$itemCount} items, total: \${$total}";
}
```

### Multitype (Polymorphic) Arrays

The main power of `DataModelArray` is handling arrays with **multiple different DataModel types**. The `discriminator()` method defines which field determines the type of each item.

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class TextContent extends DataModel
{
    #[Desc('Content type identifier')]
    public string $type = 'text';
    
    #[Desc('The text content')]
    public string $text;
}

class ImageContent extends DataModel
{
    #[Desc('Content type identifier')]
    public string $type = 'image';
    
    #[Desc('Image URL')]
    public string $url;
    
    #[Desc('Alt text for accessibility')]
    public ?string $alt = null;
}

class CodeContent extends DataModel
{
    #[Desc('Content type identifier')]
    public string $type = 'code';
    
    #[Desc('The code snippet')]
    public string $code;
    
    #[Desc('Programming language')]
    public string $language;
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class ContentArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        // Keys match the discriminator field values
        return [
            'text' => TextContent::class,
            'image' => ImageContent::class,
            'code' => CodeContent::class,
        ];
    }
    
    public function discriminator(): string
    {
        // Field name that determines which DataModel to use
        return 'type';
    }
    
    public function getTextContent(): array
    {
        return array_filter($this->items, fn($item) => $item instanceof TextContent);
    }
    
    public function getImageCount(): int
    {
        return count(array_filter($this->items, fn($item) => $item instanceof ImageContent));
    }
}
```

```php
#[Tool('Create a blog post with mixed content')]
public function createPost(string $title, ContentArray $content): string
{
    $textBlocks = count($content->getTextContent());
    $images = $content->getImageCount();
    
    return "Created post '{$title}' with {$textBlocks} text blocks and {$images} images";
}
```

### Advanced: Multiple Models per Discriminator Value

Sometimes you need multiple DataModel classes for the same discriminator value. For example, a "digital" product could be either a downloadable file or a streaming service. Use `matchesArray()` to distinguish between them:

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;

class PhysicalProduct extends DataModel
{
    #[Desc('Product category')]
    public string $category = 'physical';
    
    #[Desc('Product name')]
    public string $name;
    
    #[Desc('Weight in kg for shipping')]
    public float $weight;
    
    #[Desc('Warehouse location')]
    public string $warehouse;
}

class DownloadableProduct extends DataModel
{
    #[Desc('Product category')]
    public string $category = 'digital';
    
    #[Desc('Product name')]
    public string $name;
    
    #[Desc('Download URL')]
    public string $downloadUrl;
    
    #[Desc('File size in MB')]
    public float $fileSize;
    
    public static function matchesArray(array $data): bool
    {
        // Downloadable products have a downloadUrl
        return isset($data['downloadUrl']);
    }
}

class StreamingProduct extends DataModel
{
    #[Desc('Product category')]
    public string $category = 'digital';
    
    #[Desc('Product name')]
    public string $name;
    
    #[Desc('Streaming platform URL')]
    public string $streamUrl;
    
    #[Desc('Duration in minutes')]
    public int $duration;
    
    public static function matchesArray(array $data): bool
    {
        // Streaming products have a streamUrl
        return isset($data['streamUrl']);
    }
}
```

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class ProductCatalog extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'physical' => PhysicalProduct::class,
            'digital' => [
                // Both share category='digital', resolved via matchesArray()
                DownloadableProduct::class,
                StreamingProduct::class,
            ],
        ];
    }
    
    public function discriminator(): string
    {
        return 'category';
    }
    
    public function getDigitalProducts(): array
    {
        return array_filter($this->items, fn($p) => 
            $p instanceof DownloadableProduct || $p instanceof StreamingProduct
        );
    }
}
```

```php
#[Tool('Add products to the store catalog')]
public function addProducts(ProductCatalog $products): string
{
    $physical = 0;
    $digital = 0;
    
    foreach ($products as $product) {
        if ($product instanceof PhysicalProduct) {
            $physical++;
        } else {
            $digital++;
        }
    }
    
    return "Added {$physical} physical and {$digital} digital products";
}
```

### How Discriminator Resolution Works

1. **Single-type array** (`[ModelClass::class]`): No discriminator needed, all items use the same class
2. **Multitype array** (`['value' => ModelClass::class, ...]`): Uses discriminator field to pick the right class
3. **Multiple candidates** (`['value' => [ClassA::class, ClassB::class]]`): Calls `matchesArray()` on each candidate to find the match

This pattern is used internally by LarAgent - see `MessageArray` which handles different message types (`user`, `assistant`, `tool`, etc.) using `role` as the discriminator

## Optional DataModel Parameters

Make DataModel parameters optional with nullable types:

```php
#[Tool('Update customer information')]
public function updateCustomer(
    string $customerId,
    ?ContactInfo $newContact = null,
    ?Address $newAddress = null
): string {
    $updated = [];
    
    if ($newContact !== null) {
        $this->updateContact($customerId, $newContact);
        $updated[] = 'contact';
    }
    
    if ($newAddress !== null) {
        $this->updateAddress($customerId, $newAddress);
        $updated[] = 'address';
    }
    
    return "Updated: " . implode(', ', $updated);
}
```

## Union Types

PHP 8 union types are supported:

```php
#[Tool('Process payment')]
public function processPayment(
    string $orderId,
    CreditCard|BankAccount|PayPalAccount $paymentMethod
): string {
    // LarAgent generates a schema with oneOf for union types
    if ($paymentMethod instanceof CreditCard) {
        return $this->chargeCreditCard($orderId, $paymentMethod);
    }
    // ...
}
```

## Excluding Properties from Schema

Use `#[ExcludeFromSchema]` to hide internal properties:

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;

class Order extends DataModel
{
    #[Desc('Customer ID')]
    public string $customerId;
    
    #[Desc('Order items')]
    public array $items;
    
    // This won't be included in the schema for LLM
    #[ExcludeFromSchema]
    public ?string $internalNotes = null;
    
    // This won't be included in the schema for LLM
    #[ExcludeFromSchema]
    public ?float $calculatedTax = null;
}
```
