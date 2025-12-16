# Structured Output

Structured output allows you to define a schema for the LLM response, ensuring type-safe and predictable data from your AI agents. LarAgent supports both raw array schemas and DataModel classes for structured output.

## Table of Contents

- [Overview](#overview)
- [Setting Up Structured Output](#setting-up-structured-output)
  - [Using Array Schema](#using-array-schema)
  - [Using DataModel Class Name](#using-datamodel-class-name)
  - [Using DataModel Instance](#using-datamodel-instance)
  - [Overriding structuredOutput() Method](#overriding-structuredoutput-method)
- [DataModel Basics](#datamodel-basics)
  - [Creating a Basic DataModel](#creating-a-basic-datamodel)
  - [Property Descriptions with #[Desc]](#property-descriptions-with-desc)
  - [Optional Properties](#optional-properties)
- [Nested DataModels](#nested-datamodels)
- [DataModelArray for Collections](#datamodelarray-for-collections)
  - [Basic DataModelArray](#basic-datamodelarray)
  - [Polymorphic DataModelArray](#polymorphic-datamodelarray)
- [Response Handling](#response-handling)
  - [Automatic DataModel Reconstruction](#automatic-datamodel-reconstruction)
  - [Custom Reconstruction with Override](#custom-reconstruction-with-override)
- [Real-World Example: E-commerce Product Analysis](#real-world-example-e-commerce-product-analysis)

---

## Overview

When you use structured output, the LLM is constrained to return JSON that matches your defined schema. LarAgent then:

1. Sends the schema to the LLM provider
2. Receives JSON response
3. Automatically reconstructs DataModel instances (when applicable)

**Key Benefits:**
- Type-safe responses with IDE autocompletion
- Automatic validation via schema
- Clean separation between schema definition and usage
- Reusable DataModels across agents

---

## Setting Up Structured Output

There are multiple ways to configure structured output in your Agent.

### Using Array Schema

Define a raw JSON schema array directly:

```php
class ProductExtractorAgent extends Agent
{
    protected $responseSchema = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Product name'],
            'price' => ['type' => 'number', 'description' => 'Product price'],
            'currency' => ['type' => 'string', 'description' => 'Currency code'],
        ],
        'required' => ['name', 'price'],
    ];
}

// Usage - returns array
$response = ProductExtractorAgent::for('session-1')->respond('Extract: iPhone 15 Pro costs $999');
// $response = ['name' => 'iPhone 15 Pro', 'price' => 999, 'currency' => 'USD']
```

### Using DataModel Class Name

Reference a DataModel class by its fully qualified name:

```php
class ProductExtractorAgent extends Agent
{
    protected $responseSchema = ProductInfo::class;
}

// Usage - returns DataModel instance
$response = ProductExtractorAgent::for('session-1')->respond('Extract: iPhone 15 Pro costs $999');
// $response instanceof ProductInfo
echo $response->name;  // 'iPhone 15 Pro'
echo $response->price; // 999
```

### Using DataModel Instance

Set a DataModel instance directly using the `onInitialize` hook:

```php
class ProductExtractorAgent extends Agent
{
    protected function onInitialize()
    {
        $this->responseSchema = new ProductInfo();
    }
}
```

### Overriding structuredOutput() Method

For dynamic schemas or complex logic:

```php
class DynamicSchemaAgent extends Agent
{
    public function structuredOutput()
    {
        // Return array schema dynamically
        return [
            'type' => 'object',
            'properties' => [
                'result' => ['type' => 'string'],
            ],
        ];
    }
    
    // To enable DataModel reconstruction with custom structuredOutput(),
    // also override getResponseSchemaClass()
    public function getResponseSchemaClass(): ?string
    {
        return MyDataModel::class;
    }
}
```

### Fluent API

Set schema at runtime:

```php
$agent = MyAgent::for('session-1')
    ->responseSchema(ProductInfo::class)
    ->respond('Extract product info from: MacBook Air M3 - $1,099');
```

---

## DataModel Basics

DataModels are PHP classes that define the structure of your expected response.

### Creating a Basic DataModel

```php
<?php

namespace App\DataModels;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;

class WeatherInfo extends DataModel
{
    #[Desc('The city name')]
    public string $city;
    
    #[Desc('Temperature in Celsius')]
    public float $temperature;
    
    #[Desc('Weather condition (sunny, cloudy, rainy, etc.)')]
    public string $condition;
    
    #[Desc('Humidity percentage (0-100)')]
    public int $humidity;
}
```

### Property Descriptions with #[Desc]

The `#[Desc]` attribute adds descriptions to your schema, helping the LLM understand what each field should contain:

```php
use LarAgent\Attributes\Desc;

class UserProfile extends DataModel
{
    #[Desc('Full legal name of the user')]
    public string $name;
    
    #[Desc('Age in years, must be positive integer')]
    public int $age;
    
    #[Desc('Valid email address format')]
    public string $email;
}
```

### Optional Properties

Use nullable types or default values for optional fields:

```php
class ContactInfo extends DataModel
{
    #[Desc('Primary phone number')]
    public string $phone;
    
    #[Desc('Secondary phone number (optional)')]
    public ?string $alternatePhone = null;
    
    #[Desc('Preferred contact method')]
    public string $preferredMethod = 'email';
}
```

### Supported Property Types

- `string` - Text values
- `int` - Integer numbers
- `float` - Decimal numbers
- `bool` - Boolean true/false
- `array` - Generic arrays
- `?type` - Nullable types
- Nested `DataModel` classes
- `DataModelArray` for typed collections
- PHP Enums (string-backed and int-backed)

---

## Nested DataModels

DataModels can contain other DataModels for complex structures:

```php
class Address extends DataModel
{
    #[Desc('Street address including number')]
    public string $street;
    
    #[Desc('City name')]
    public string $city;
    
    #[Desc('State or province')]
    public string $state;
    
    #[Desc('Postal/ZIP code')]
    public string $postalCode;
    
    #[Desc('Country name')]
    public string $country;
}

class Company extends DataModel
{
    #[Desc('Official company name')]
    public string $name;
    
    #[Desc('Industry sector')]
    public string $industry;
    
    #[Desc('Company headquarters address')]
    public Address $headquarters;
    
    #[Desc('Year the company was founded')]
    public int $foundedYear;
}

class Person extends DataModel
{
    #[Desc('Person full name')]
    public string $name;
    
    #[Desc('Job title')]
    public string $title;
    
    #[Desc('Current employer')]
    public Company $employer;
    
    #[Desc('Home address')]
    public Address $homeAddress;
}
```

**Usage:**

```php
class PersonExtractorAgent extends Agent
{
    protected $responseSchema = Person::class;
    
    public function instructions()
    {
        return 'Extract person information from the provided text.';
    }
}

$response = PersonExtractorAgent::for('extract')
    ->respond('John Smith is a Senior Developer at TechCorp Inc, 
               a software company founded in 2010 headquartered at 
               123 Tech Street, San Francisco, CA 94102, USA. 
               He lives at 456 Home Ave, Oakland, CA 94601, USA.');

// Access nested data with full type safety
echo $response->name;                          // 'John Smith'
echo $response->employer->name;                // 'TechCorp Inc'
echo $response->employer->headquarters->city;  // 'San Francisco'
echo $response->homeAddress->city;             // 'Oakland'
```

---

## DataModelArray for Collections

When you need arrays of DataModels, use `DataModelArray` for type-safe collections.

### Basic DataModelArray

```php
use LarAgent\Core\Abstractions\DataModelArray;

class Skill extends DataModel
{
    #[Desc('Skill name')]
    public string $name;
    
    #[Desc('Proficiency level: beginner, intermediate, advanced, expert')]
    public string $level;
    
    #[Desc('Years of experience with this skill')]
    public int $yearsOfExperience;
}

class SkillArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [Skill::class];
    }
}

class CandidateProfile extends DataModel
{
    #[Desc('Candidate full name')]
    public string $name;
    
    #[Desc('List of technical and soft skills')]
    public SkillArray $skills;
    
    #[Desc('Total years of professional experience')]
    public int $totalExperience;
}
```

**Usage:**

```php
class CandidateAnalyzerAgent extends Agent
{
    protected $responseSchema = CandidateProfile::class;
    
    public function instructions()
    {
        return 'Analyze the resume and extract candidate profile with skills.';
    }
}

$response = CandidateAnalyzerAgent::for('resume')
    ->respond('Jane Doe - 8 years experience. Expert in PHP (6 years), 
               Advanced Laravel (4 years), Intermediate Vue.js (2 years).');

echo $response->name; // 'Jane Doe'

// Iterate over skills
foreach ($response->skills as $skill) {
    echo "{$skill->name}: {$skill->level} ({$skill->yearsOfExperience} years)\n";
}
// Output:
// PHP: expert (6 years)
// Laravel: advanced (4 years)
// Vue.js: intermediate (2 years)
```

### Polymorphic DataModelArray

For collections that can contain different types of DataModels:

```php
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
    
    #[Desc('Alt text description')]
    public string $altText;
}

class CodeContent extends DataModel
{
    #[Desc('Content type identifier')]
    public string $type = 'code';
    
    #[Desc('Programming language')]
    public string $language;
    
    #[Desc('The code snippet')]
    public string $code;
}

class ContentBlockArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [
            'text' => TextContent::class,
            'image' => ImageContent::class,
            'code' => CodeContent::class,
        ];
    }
    
    // The discriminator field determines which model to use
    public function discriminator(): string
    {
        return 'type';
    }
}

class ArticleAnalysis extends DataModel
{
    #[Desc('Article title')]
    public string $title;
    
    #[Desc('Content blocks extracted from the article')]
    public ContentBlockArray $contentBlocks;
}
```

---

## Response Handling

### Automatic DataModel Reconstruction

When `$responseSchema` is set to a DataModel class (string) or instance, the response is automatically reconstructed:

```php
class WeatherAgent extends Agent
{
    protected $responseSchema = WeatherInfo::class;
}

$response = WeatherAgent::for('weather')->respond('What is the weather in Paris?');

// $response is a WeatherInfo instance
if ($response instanceof WeatherInfo) {
    echo "Temperature: {$response->temperature}°C";
    echo "Condition: {$response->condition}";
}
```

### Custom Reconstruction with Override

When you override `structuredOutput()` to return an array but still want DataModel reconstruction:

```php
class CustomSchemaAgent extends Agent
{
    // Returns array schema
    public function structuredOutput()
    {
        return WeatherInfo::generateSchema();
    }
    
    // Enable reconstruction by providing the class
    public function getResponseSchemaClass(): ?string
    {
        return WeatherInfo::class;
    }
}
```

### Disabling DataModel Reconstruction

If you prefer raw arrays even with DataModel schemas, override `getResponseSchemaClass()` to return `null`:

```php
class RawResponseAgent extends Agent
{
    protected $responseSchema = WeatherInfo::class;
    
    // Return null to disable automatic DataModel reconstruction
    public function getResponseSchemaClass(): ?string
    {
        return null;
    }
}
```

---

## Real-World Example: E-commerce Product Analysis

A complete example showing all concepts together - analyzing e-commerce product reviews.

### DataModels

```php
<?php

namespace App\DataModels\Ecommerce;

use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Abstractions\DataModelArray;

/**
 * Represents a single aspect of a product review (e.g., quality, price, shipping)
 */
class ReviewAspect extends DataModel
{
    #[Desc('The aspect being evaluated (quality, price, shipping, packaging, etc.)')]
    public string $aspect;
    
    #[Desc('Sentiment: positive, negative, or neutral')]
    public string $sentiment;
    
    #[Desc('Confidence score from 0.0 to 1.0')]
    public float $confidence;
    
    #[Desc('Key phrases from the review supporting this analysis')]
    public array $supportingPhrases;
}

class ReviewAspectArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [ReviewAspect::class];
    }
}

/**
 * Represents a competitor product mentioned in reviews
 */
class CompetitorMention extends DataModel
{
    #[Desc('Competitor product or brand name')]
    public string $name;
    
    #[Desc('How the product compares: better, worse, or similar')]
    public string $comparison;
    
    #[Desc('The context in which the competitor was mentioned')]
    public string $context;
}

class CompetitorMentionArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [CompetitorMention::class];
    }
}

/**
 * Represents a single analyzed review
 */
class AnalyzedReview extends DataModel
{
    #[Desc('Original review ID or index')]
    public string $reviewId;
    
    #[Desc('Overall sentiment: positive, negative, mixed, or neutral')]
    public string $overallSentiment;
    
    #[Desc('Sentiment score from -1.0 (very negative) to 1.0 (very positive)')]
    public float $sentimentScore;
    
    #[Desc('Detailed aspect-based analysis')]
    public ReviewAspectArray $aspects;
    
    #[Desc('Any competitor products mentioned')]
    public CompetitorMentionArray $competitorMentions;
    
    #[Desc('Key actionable insight from this review')]
    public ?string $actionableInsight = null;
}

class AnalyzedReviewArray extends DataModelArray
{
    public static function allowedModels(): array
    {
        return [AnalyzedReview::class];
    }
}

/**
 * Summary statistics for the analysis
 */
class AnalysisSummary extends DataModel
{
    #[Desc('Total number of reviews analyzed')]
    public int $totalReviews;
    
    #[Desc('Number of positive reviews')]
    public int $positiveCount;
    
    #[Desc('Number of negative reviews')]
    public int $negativeCount;
    
    #[Desc('Number of neutral/mixed reviews')]
    public int $neutralCount;
    
    #[Desc('Average sentiment score across all reviews')]
    public float $averageSentiment;
    
    #[Desc('Most frequently mentioned positive aspects')]
    public array $topPositiveAspects;
    
    #[Desc('Most frequently mentioned negative aspects')]
    public array $topNegativeAspects;
    
    #[Desc('Most mentioned competitor products')]
    public array $topCompetitors;
}

/**
 * Complete product review analysis response
 */
class ProductReviewAnalysis extends DataModel
{
    #[Desc('Product name being analyzed')]
    public string $productName;
    
    #[Desc('Analysis timestamp in ISO 8601 format')]
    public string $analyzedAt;
    
    #[Desc('Summary statistics of the analysis')]
    public AnalysisSummary $summary;
    
    #[Desc('Detailed analysis of each review')]
    public AnalyzedReviewArray $reviews;
    
    #[Desc('Overall recommendation based on the analysis')]
    public string $recommendation;
    
    #[Desc('Priority areas for product improvement')]
    public array $improvementPriorities;
}
```

### Agent Implementation

```php
<?php

namespace App\Agents;

use App\DataModels\Ecommerce\ProductReviewAnalysis;
use LarAgent\Agent;

class ProductReviewAnalyzerAgent extends Agent
{
    protected $provider = 'openai';
    protected $model = 'gpt-4o';
    protected $history = 'in_memory';
    
    protected $responseSchema = ProductReviewAnalysis::class;
    
    public function instructions()
    {
        return <<<PROMPT
You are an expert e-commerce product analyst. Your task is to analyze customer reviews 
and provide actionable insights for product managers.

Guidelines:
1. Identify specific aspects mentioned in each review (quality, price, shipping, etc.)
2. Detect sentiment at both review and aspect level
3. Note any competitor comparisons
4. Extract actionable insights that could improve the product or customer experience
5. Provide a prioritized list of improvement areas

Be thorough but concise. Focus on patterns across reviews, not just individual opinions.
PROMPT;
    }
    
    public function prompt(string $message)
    {
        return "Analyze the following product reviews:\n\n{$message}";
    }
}
```

### Usage Example

```php
$reviews = <<<REVIEWS
Review 1: "Great headphones! Sound quality is amazing, much better than my old Sony pair. 
           Noise cancellation is top-notch. Only complaint is the case feels a bit cheap. 
           Shipping was fast, arrived in 2 days."

Review 2: "Decent for the price but not as good as Bose QC45. The bass is weak and 
           they hurt my ears after an hour. Battery life is good though."

Review 3: "Absolutely love these! Comfortable for all-day wear, excellent build quality. 
           The app is intuitive. Worth every penny."

Review 4: "Mixed feelings. Sound is good but connectivity issues with my iPhone. 
           Had to reconnect multiple times a day. Customer service was helpful though."

Review 5: "Returned them. The noise cancellation created a weird pressure in my ears. 
           Going back to my AirPods Max."
REVIEWS;

$analysis = ProductReviewAnalyzerAgent::for('analysis-session')
    ->respond($reviews);

// Access the fully typed response
echo "Product: {$analysis->productName}\n";
echo "Average Sentiment: {$analysis->summary->averageSentiment}\n";
echo "Positive: {$analysis->summary->positiveCount}, Negative: {$analysis->summary->negativeCount}\n\n";

echo "Top Positive Aspects:\n";
foreach ($analysis->summary->topPositiveAspects as $aspect) {
    echo "  - {$aspect}\n";
}

echo "\nAreas for Improvement:\n";
foreach ($analysis->improvementPriorities as $priority) {
    echo "  - {$priority}\n";
}

echo "\nDetailed Review Analysis:\n";
foreach ($analysis->reviews as $review) {
    echo "\n{$review->reviewId}: {$review->overallSentiment} ({$review->sentimentScore})\n";
    
    foreach ($review->aspects as $aspect) {
        echo "  - {$aspect->aspect}: {$aspect->sentiment}\n";
    }
    
    if (count($review->competitorMentions) > 0) {
        echo "  Competitors mentioned:\n";
        foreach ($review->competitorMentions as $competitor) {
            echo "    - {$competitor->name}: {$competitor->comparison}\n";
        }
    }
    
    if ($review->actionableInsight) {
        echo "  💡 Insight: {$review->actionableInsight}\n";
    }
}

echo "\n📋 Recommendation: {$analysis->recommendation}\n";
```

### Expected Output

```
Product: Wireless Headphones
Average Sentiment: 0.35
Positive: 2, Negative: 2

Top Positive Aspects:
  - Sound quality
  - Comfort
  - Battery life

Areas for Improvement:
  - Noise cancellation comfort (pressure issue)
  - Bluetooth connectivity reliability
  - Case build quality

Detailed Review Analysis:

review-1: positive (0.75)
  - sound_quality: positive
  - noise_cancellation: positive
  - build_quality: negative
  - shipping: positive
  Competitors mentioned:
    - Sony: better
  💡 Insight: Consider upgrading the carrying case material

review-2: mixed (-0.2)
  - value: positive
  - sound_quality: negative
  - comfort: negative
  - battery: positive
  Competitors mentioned:
    - Bose QC45: worse
  💡 Insight: Address bass response and long-wear comfort

review-3: positive (0.95)
  - comfort: positive
  - build_quality: positive
  - software: positive
  - value: positive
  💡 Insight: Highlight all-day comfort in marketing

review-4: mixed (0.1)
  - sound_quality: positive
  - connectivity: negative
  - customer_service: positive
  Competitors mentioned:
    - iPhone/Apple: worse
  💡 Insight: Investigate iOS Bluetooth compatibility issues

review-5: negative (-0.8)
  - noise_cancellation: negative
  Competitors mentioned:
    - AirPods Max: worse
  💡 Insight: Add adjustable NC pressure levels

📋 Recommendation: Focus on firmware update for connectivity and consider 
    adjustable noise cancellation levels to address ear pressure complaints.
```

---

## Summary

| Feature | Use Case |
|---------|----------|
| Array Schema | Quick schemas, dynamic generation |
| DataModel Class | Type-safe responses, IDE support |
| DataModel Instance | Runtime-configured schemas |
| Nested DataModels | Complex hierarchical data |
| DataModelArray | Typed collections |
| `getResponseSchemaClass()` | Custom schema with DataModel reconstruction |
| `processArrayResponse()` | Custom response processing |

**Best Practices:**
1. Use DataModels for reusability across agents
2. Add `#[Desc]` attributes to guide the LLM
3. Use DataModelArray for collections instead of raw arrays
4. Keep DataModels focused - split complex structures into nested models
5. Override `getResponseSchemaClass()` when using dynamic schemas with DataModel reconstruction
