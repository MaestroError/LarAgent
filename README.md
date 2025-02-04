# LarAgent

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maestroerror/laragent.svg?style=flat-square)](https://packagist.org/packages/maestroerror/laragent)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/maestroerror/laragent/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/maestroerror/laragent/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/maestroerror/laragent/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/maestroerror/laragent/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/maestroerror/laragent.svg?style=flat-square)](https://packagist.org/packages/maestroerror/laragent)

## Introduction

LarAgent brings the power of AI agents to your Laravel projects with an elegant, Eloquent-like syntax. Create, extend, and manage AI agents with ease while maintaining Laravel's fluent API design patterns.

// @todo note about usage outside of Laravel

// @todo small example

## Features

// @todo list of features (all)

## Table of Contents

- [Introduction](#introduction)
- [Getting Started](#getting-started)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [Core Concepts](#core-concepts)
  - [Agents](#agents)
  - [Tools](#tools)
  - [Chat History](#chat-history)
  - [Structured Output](#structured-output)
  - [Usage without Laravel](#usage-in-and-outside-of-laravel)
- [Basic Usage](#basic-usage)
  - [Creating an Agent](#creating-an-agent)
  - [Using Tools](#using-tools)
  - [Managing Chat History](#managing-chat-history)
- [Commands](#commands)
  - [Creating an Agent](#creating-an-agent-1)
  - [Interactive Chat](#interactive-chat)
- [Advanced Usage](#advanced-usage)
  - [Custom Agents](#custom-agents)
  - [Custom Tools](#custom-tools)
  - [Providers and Models](#providers-and-models)
  - [Advanced Configuration](#advanced-configuration)
- [Examples](#examples)
  - [Weather Agent Example](#weather-agent-example)
  - [Common Use Cases](#common-use-cases)
- [Contributing](#contributing)
- [Testing](#testing)
- [Security](#security)
- [Credits](#credits)
- [License](#license)
- [Roadmap](#roadmap)

## Getting Started

### Requirements

*   Laravel 10.x or higher
*   PHP 8.3 or higher

### Installation

You can install the package via composer:

```bash
composer require maestroerror/laragent
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laragent-config"
```

This is the contents of the published config file:

```php
return [
    'default_driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
    'default_chat_history' => \LarAgent\History\InMemoryChatHistory::class,

    'providers' => [

        'default' => [
            'name' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'default_context_window' => 50000,
            'default_max_completion_tokens' => 100,
            'default_temperature' => 1,
        ],
    ],
];

```

### Configuration

You can configure the package by editing the `config/laragent.php` file. Here is an example of custom provider with all possible configurations you can apply:

```php
    // Example custom provider with all possible configurations
    'custom_provider' => [
        // Just name for reference, changes nothing
        'name' => 'mini',
        'model' => 'gpt-3.5-turbo',
        'api_key' => env('CUSTOM_API_KEY'),
        'api_url' => env('CUSTOM_API_URL'),
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'chat_history' => \LarAgent\History\InMemoryChatHistory::class,
        'default_context_window' => 15000,
        'default_max_completion_tokens' => 100,
        'default_temperature' => 1,
        'parallel_tool_calls' => true,
        // Store metadata with messages
        'store_meta' => true,
    ],
```

## Core Concepts

### Agents

Agents are the core of LarAgent. They represent a conversational AI model that can be used to interact with users, systems, or any other source of input.

### Tools

Tools are used to extend the functionality of agents. They can be used to perform tasks such as sending messages, running jobs, making API calls, or executing shell commands.

// @todo small example and link to Using Tools section


### Chat History

Chat history is used to store the conversation history between the user and the agent.

// @todo What types of chat histories are supported now? In Laravel and outside?
// @todo how can be used?

### Structured Output

Structured output is used to define the format (JSON Schema) of the output generated by the agent.

// @todo how can be used in laravel?


### Usage in and outside of Laravel

// @todo add link to usage out of laravel documentation


## Basic Usage

### Creating an Agent

You can create an agent by extending the `LarAgent\Agent` class.

// @todo add agent creation command here

Here is an example of bery basic agent created by extending `LarAgent\Agent`:

```php

namespace App\AiAgents;

use LarAgent\Agent;
use App\AiTools\WeatherTool; // Example tool

class WeatherAgent extends Agent
{
    protected $model = "gpt-4o-mini";

    // Tool by classes
    protected $tools = [
        WeatherTool::class
    ];

    // Built in chat histories: "in_memory", "session", "cache", "file", "json"
    protected $history = "in_memory";

    public function instructions() {
        return "You are weather agent holding info about weather in any city.";
    }

    public function prompt($message) {
        return $message . ". Always check if I have other questions.";
    }
}
```

### Using Tools

You can use tools to extend the functionality of agents.

// @todo add examples of all types of tools creation and registration here

### Managing Chat History

You can manage chat history by using agent class per key or user.

// @todo add chat history management methods

## Commands

### Creating an Agent

You can quickly create a new agent using the `make:agent` command:

```bash
php artisan make:agent WeatherAgent
```

This will create a new agent class in your `app/AiAgents` directory with the basic structure and methods needed to get started.

### Interactive Chat

You can start an interactive chat session with any of your agents using the `agent:chat` command:

```bash
# Start a chat with default history name
php artisan agent:chat WeatherAgent

# Start a chat with a specific history name
php artisan agent:chat WeatherAgent --history=weather_chat_1
```

The chat session allows you to:
- Send messages to your agent
- Get responses in real-time
- Use any tools configured for the agent
- Type 'exit' to end the chat session

## Advanced Usage

### Ai agents as Tools

You can create tools which calls another agent and bind the result to the agent to create a chain or complex workflow.

// @todo add example


### Providers and chat histories

You can use custom providers and models to extend the functionality of agents.

// @todo add example


## Examples

### Weather Agent Example

You can use the `WeatherAgent` class to create a weather agent.

```php
use LarAgent\Attributes\Tool;
use LarAgent\Core\Contracts\ChatHistory;

class WeatherAgent extends LarAgent\Agent
{
    protected $model = "gpt-4o-mini";

    // Tool by classes
    protected $tools = [
        WeatherTool::class
    ];

    // Built in chat histories: "in_memory", "session", "cache", "file", "json"
    protected $history = "in_memory";

    // Or Define history with custom options or using custom history class
    // Note that defining createChatHistory method overrides the property-defined history
    public function createChatHistory($name) {
        return new LarAgent\History\JsonChatHistory($name, ['folder' => __DIR__.'/json_History']);
    }

    // Define instructions with external info
    public function instructions() {
        $user = auth()->user();
        return 
            "You are weather agent holding info about weather in any city.
            Always use User's name while responding.
            User info: " . json_encode($user->toArray());
    }

    // Define prompt using blade
    public function prompt($message) {
        return view('ai.prompts.weather', ['message' => $message])->render();
    }

    // Register quickly tools using \LarAgent\Tool
    public function registerTools() {
        $user = auth()->user();
        return [
            // Tool without properties
            \LarAgent\Tool::create("user_location", "Returns user's current location")
                 ->setCallback(function () use ($user) {
                      return $user->location;
                 }),
        ];
    }


    // Example of a tool defined as a method with optional and required parameters
    #[Tool("Get the current weather in a given location")]
    public function weatherTool($location, $unit = 'celsius') {
        return 'The weather in '.$location.' is ' . "20" . ' degrees '.$unit;
    }


    // Example of using static method as tool and all it's features
    // Tool Description, property descriptions, enums, required properties
    #[Tool("Get the current weather in a given location", ['unit' => "Unit of temperature"])]
    public static function weatherToolForNewYork(Unit $unit) {
        return 'The weather in New York is ' . "50" . ' degrees '. $unit->value;
    }
}
```

### Common Use Cases

You can use LarAgent to create conversational AI models for various use cases such as customer support, language translation, and more.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Testing

```bash
composer test
```

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [maestroerror](https://github.com/maestroerror)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Roadmap

Please see [ROADMAP](ROADMAP.md) for more information on the future development of LarAgent.
