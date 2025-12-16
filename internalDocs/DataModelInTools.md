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

You can use arrays of DataModels with proper type hints:

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

For arrays of DataModels, use DataModelArray:

```php
<?php

namespace App\DataModels;

use LarAgent\Core\Abstractions\DataModelArray;

class LineItemArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'default' => LineItem::class,
        ];
    }
    
    public function discriminator(): string
    {
        return 'type';
    }
    
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

## Manual DataModel Registration in Tools

For more complex scenarios, you can manually handle DataModels in tools:

```php
use LarAgent\Tool;
use App\DataModels\Address;

public function registerTools()
{
    return [
        Tool::create('validate_address', 'Validate a delivery address')
            ->addProperty('address', [
                'type' => 'object',
                'properties' => Address::generateSchema()['properties'],
                'required' => ['street', 'city', 'state', 'postalCode'],
            ], 'The address to validate')
            ->setRequired('address')
            ->setCallback(function (array $address) {
                // Manually convert to DataModel
                $addressModel = Address::fromArray($address);
                return $this->validateAddress($addressModel);
            }),
    ];
}
```

## Real-World Scenario: E-Commerce Order System

### Requirements
- Accept complex order structures from AI
- Validate and process orders
- Handle multiple payment methods
- Support various shipping options

### Implementation

#### 1. Define DataModels

```php
<?php

namespace App\DataModels\Ecommerce;

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Attributes\Desc;
use LarAgent\Attributes\ExcludeFromSchema;

class ProductItem extends DataModel
{
    #[Desc('Product SKU')]
    public string $sku;
    
    #[Desc('Quantity to purchase')]
    public int $quantity;
    
    #[Desc('Special instructions for this item')]
    public ?string $notes = null;
}

class ShippingOptions extends DataModel
{
    #[Desc('Shipping method: standard, express, overnight')]
    public string $method = 'standard';
    
    #[Desc('Require signature on delivery')]
    public bool $requireSignature = false;
    
    #[Desc('Gift wrap the package')]
    public bool $giftWrap = false;
    
    #[Desc('Gift message (if gift wrap is enabled)')]
    public ?string $giftMessage = null;
}

class PaymentInfo extends DataModel
{
    #[Desc('Payment method: credit_card, paypal, bank_transfer')]
    public string $method;
    
    #[Desc('Last 4 digits of card (for credit card)')]
    public ?string $cardLast4 = null;
    
    #[Desc('PayPal email (for PayPal)')]
    public ?string $paypalEmail = null;
}

class CustomerDetails extends DataModel
{
    #[Desc('Customer full name')]
    public string $name;
    
    #[Desc('Email address')]
    public string $email;
    
    #[Desc('Phone number')]
    public ?string $phone = null;
    
    #[Desc('Shipping address')]
    public Address $shippingAddress;
    
    #[Desc('Use same address for billing')]
    public bool $sameAsBilling = true;
    
    #[Desc('Billing address (if different)')]
    public ?Address $billingAddress = null;
}

class OrderRequest extends DataModel
{
    #[Desc('Customer information')]
    public CustomerDetails $customer;
    
    #[Desc('Items to order')]
    public array $items; // Array of ProductItem
    
    #[Desc('Shipping preferences')]
    public ShippingOptions $shipping;
    
    #[Desc('Payment information')]
    public PaymentInfo $payment;
    
    #[Desc('Promotional code if any')]
    public ?string $promoCode = null;
    
    #[Desc('Additional order notes')]
    public ?string $notes = null;
    
    // Internal fields not exposed to LLM
    #[ExcludeFromSchema]
    public ?string $orderId = null;
    
    #[ExcludeFromSchema]
    public ?float $totalAmount = null;
}
```

#### 2. Create the Agent

```php
<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\DataModels\Ecommerce\OrderRequest;
use App\DataModels\Ecommerce\ProductItem;
use App\DataModels\Ecommerce\CustomerDetails;
use App\Services\OrderService;
use App\Services\InventoryService;
use App\Services\ShippingService;

class EcommerceAssistant extends Agent
{
    protected $instructions = <<<PROMPT
You are an e-commerce assistant helping customers place orders.
You can:
- Check product availability
- Calculate shipping costs
- Process orders
- Apply promotional codes

Always confirm order details before processing.
PROMPT;

    public function __construct(
        string $key,
        protected OrderService $orderService,
        protected InventoryService $inventory,
        protected ShippingService $shipping
    ) {
        parent::__construct($key);
    }
    
    #[Tool(
        description: 'Check if products are available',
        parameterDescriptions: [
            'items' => 'List of products to check',
        ]
    )]
    public function checkAvailability(array $items): string
    {
        $results = [];
        
        foreach ($items as $itemData) {
            $item = ProductItem::fromArray($itemData);
            $available = $this->inventory->checkStock($item->sku, $item->quantity);
            $results[] = [
                'sku' => $item->sku,
                'requested' => $item->quantity,
                'available' => $available,
                'in_stock' => $available >= $item->quantity,
            ];
        }
        
        return json_encode($results);
    }
    
    #[Tool(
        description: 'Calculate shipping cost for an order',
        parameterDescriptions: [
            'customer' => 'Customer details with shipping address',
            'items' => 'Products to ship',
            'method' => 'Shipping method: standard, express, overnight',
        ]
    )]
    public function calculateShipping(
        CustomerDetails $customer,
        array $items,
        string $method = 'standard'
    ): string {
        $weight = 0;
        foreach ($items as $itemData) {
            $item = ProductItem::fromArray($itemData);
            $weight += $this->inventory->getProductWeight($item->sku) * $item->quantity;
        }
        
        $cost = $this->shipping->calculate(
            $customer->shippingAddress,
            $weight,
            $method
        );
        
        return json_encode([
            'method' => $method,
            'weight_kg' => $weight,
            'cost' => $cost,
            'estimated_days' => $this->shipping->getEstimatedDays($method),
        ]);
    }
    
    #[Tool(
        description: 'Process a complete order',
        parameterDescriptions: [
            'order' => 'Complete order details including customer, items, shipping, and payment',
        ]
    )]
    public function processOrder(OrderRequest $order): string
    {
        // Validate inventory
        foreach ($order->items as $itemData) {
            $item = ProductItem::fromArray($itemData);
            if (!$this->inventory->checkStock($item->sku, $item->quantity)) {
                return json_encode([
                    'success' => false,
                    'error' => "Product {$item->sku} is out of stock",
                ]);
            }
        }
        
        // Calculate totals
        $subtotal = $this->calculateSubtotal($order->items);
        $shippingCost = $this->shipping->calculate(
            $order->customer->shippingAddress,
            $this->calculateWeight($order->items),
            $order->shipping->method
        );
        
        $discount = 0;
        if ($order->promoCode) {
            $discount = $this->orderService->validatePromoCode($order->promoCode, $subtotal);
        }
        
        $total = $subtotal + $shippingCost - $discount;
        
        // Process the order
        try {
            $orderId = $this->orderService->create([
                'customer' => $order->customer->toArray(),
                'items' => $order->items,
                'shipping' => $order->shipping->toArray(),
                'payment' => $order->payment->toArray(),
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
            ]);
            
            return json_encode([
                'success' => true,
                'order_id' => $orderId,
                'subtotal' => $subtotal,
                'shipping' => $shippingCost,
                'discount' => $discount,
                'total' => $total,
                'estimated_delivery' => $this->shipping->getEstimatedDelivery(
                    $order->shipping->method,
                    $order->customer->shippingAddress
                ),
            ]);
        } catch (\Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    #[Tool('Apply a promotional code and get discount info')]
    public function applyPromoCode(string $code, float $orderTotal): string
    {
        $discount = $this->orderService->validatePromoCode($code, $orderTotal);
        
        if ($discount > 0) {
            return json_encode([
                'valid' => true,
                'code' => $code,
                'discount' => $discount,
                'new_total' => $orderTotal - $discount,
            ]);
        }
        
        return json_encode([
            'valid' => false,
            'code' => $code,
            'message' => 'Invalid or expired promotional code',
        ]);
    }
    
    protected function calculateSubtotal(array $items): float
    {
        $total = 0;
        foreach ($items as $itemData) {
            $item = ProductItem::fromArray($itemData);
            $price = $this->inventory->getProductPrice($item->sku);
            $total += $price * $item->quantity;
        }
        return $total;
    }
    
    protected function calculateWeight(array $items): float
    {
        $weight = 0;
        foreach ($items as $itemData) {
            $item = ProductItem::fromArray($itemData);
            $weight += $this->inventory->getProductWeight($item->sku) * $item->quantity;
        }
        return $weight;
    }
}
```

#### 3. Controller Usage

```php
<?php

namespace App\Http\Controllers;

use App\AiAgents\EcommerceAssistant;
use App\Services\OrderService;
use App\Services\InventoryService;
use App\Services\ShippingService;
use Illuminate\Http\Request;

class OrderChatController extends Controller
{
    public function chat(Request $request)
    {
        $sessionId = $request->session()->getId();
        
        $agent = new EcommerceAssistant(
            $sessionId,
            app(OrderService::class),
            app(InventoryService::class),
            app(ShippingService::class)
        );
        
        $response = $agent->respond($request->input('message'));
        
        return response()->json([
            'response' => $response,
        ]);
    }
}
```

#### 4. Example Conversation

**User**: I want to order 2 units of SKU-12345 and 1 unit of SKU-67890

**Assistant**: Let me check the availability of those products for you.
*[Calls checkAvailability tool]*

Both products are in stock! Here's what I found:
- SKU-12345: 15 units available ✓
- SKU-67890: 8 units available ✓

Would you like me to proceed with the order? I'll need your shipping address and preferred shipping method.

**User**: Ship to John Doe, 123 Main St, New York, NY 10001. Use express shipping.

**Assistant**: *[Calls calculateShipping tool]*

Here's your shipping estimate:
- Method: Express
- Cost: $15.99
- Estimated delivery: 2-3 business days

Your order summary:
- SKU-12345 x 2: $49.98
- SKU-67890 x 1: $29.99
- Subtotal: $79.97
- Shipping: $15.99
- **Total: $95.96**

Would you like to proceed with the order? Please provide your payment method.

**User**: Pay with credit card ending in 4242

**Assistant**: *[Calls processOrder tool with complete OrderRequest]*

Order successfully placed! 🎉

Order #ORD-2024-001234
- Total charged: $95.96
- Estimated delivery: January 18, 2024

You'll receive a confirmation email at the address provided. Is there anything else I can help you with?
