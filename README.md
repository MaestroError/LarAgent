# LarAgent

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maestroerror/laragent.svg?style=flat-square)](https://packagist.org/packages/maestroerror/laragent)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/maestroerror/laragent/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/maestroerror/laragent/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/maestroerror/laragent/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/maestroerror/laragent/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/maestroerror/laragent.svg?style=flat-square)](https://packagist.org/packages/maestroerror/laragent)

The **easiest** way to **create** and **maintain** AI agents in your Laravel projects.

---

Each GitHub ⭐️ helps the community grow. Thanks for the support!

Jump to [Official Documentation](https://docs.laragent.ai/)

__If you prefer article to get started, check it out: [Laravel AI Agent Development Made Easy](https://medium.com/towardsdev/laravel-ai-agent-development-made-easy-ac7ddd17a7d0)__

You can find other tutorials here: [Tutorials](https://blog.laragent.ai/tag/tutorials/)

---
       
Any questions? Join our [Discord](https://discord.gg/NAczq2T9F8) server!
       
---

_Need to use LarAgent outside of Laravel? Check out this [Docs](https://docs.laragent.ai/core-concepts/usage-without-laravel)._

## Table of Contents

- [📖 Introduction](#introduction)
- [🎉 Features](#features) 
- [📅 Planned](#planned) 
- [🚀 Getting Started](#getting-started)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [🤝 Contributing](#contributing)
- [🤝 Getting Help](#getting-help)
- [🧪 Testing](#testing)
- [🔒 Security](#security)
- [🙌 Credits](#credits)
- [📜 License](#license)
- [🛣️ Roadmap](#roadmap)

## Introduction

LarAgent brings the power of AI agents to your Laravel projects with an elegant syntax. Create, extend, and manage AI agents with ease while maintaining Laravel's fluent API design patterns.

What if you can create AI agents just like you create any other Eloquent model?

Why not?! 👇

```bash
php artisan make:agent YourAgentName
```

And it looks familiar, isn't it?

```php
namespace App\AiAgents;

use LarAgent\Agent;

class YourAgentName extends Agent
{
    protected $model = 'gpt-4';

    protected $history = 'in_memory';

    protected $provider = 'default';

    protected $tools = [];

    public function instructions()
    {
        return "Define your agent's instructions here.";
    }

    public function prompt($message)
    {
        return $message;
    }
}

```

And you can tweak the configs, like `history`

```php
// ...
protected $history = \LarAgent\History\CacheChatHistory::class;
// ...
```

Or add `temperature`:
 
```php
// ...
protected $temperature = 0.5;
// ...
```
Even disable parallel tool calls:
 
```php
// ...
protected $parallelToolCalls = false;
// ...
```

Oh, and add a new tool as well:

```php
// ...
#[Tool('Get the current weather in a given location')]
public function exampleWeatherTool($location, $unit = 'celsius')
{
    return 'The weather in '.$location.' is '.'20'.' degrees '.$unit;
}
// ...
```

And run it, per user:

```php
Use App\AiAgents\YourAgentName;
// ...
YourAgentName::forUser(auth()->user())->respond($message);
```

Or use a custom name for the chat history:

```php
Use App\AiAgents\YourAgentName;
// ...
YourAgentName::for("custom_history_name")->respond($message);
```

Let's find out more in [documentation](https://docs.laragent.ai/) 👍


## Features

- Eloquent-like syntax for creating and managing AI agents
- Laravel-style artisan commands
- Flexible agent configuration (model, temperature, context window, etc.)
- Structured output handling
- Image input support
- Easily extendable, including chat histories and LLM drivers
- Multiple built-in chat history storage options (in-memory, cache, json, etc.)
    - Per-user chat history management
    - Custom chat history naming support
- Custom tool creation with attribute-based configuration
    - Tools via classes
    - Tools via methods of AI agent class (Auto)
    - `Tool` facade for shortened tool creation
    - Parallel tool execution capability (can be disabled)
- Extensive Event system for agent interactions (Nearly everything is hookable)
- Multiple provider support (Can be set per model)
- Support for both Laravel and standalone usage

## Planned

Here's what's coming next to make LarAgent even more powerful:

### Developer Experience 🛠️
- **Artisan Commands for Rapid Development**
  - `agent:chat:clear AgentName` - Clear all chat histories for a specific agent while preserving keys ✔️
  - `agent:chat:remove AgentName` - Completely remove all chat histories and keys for a specific agent ✔️
  - `make:agent:tool` - Generate tool classes with ready-to-use stubs
  - `make:agent:chat-history` - Scaffold custom chat history implementations
  - `make:llm-driver` - Create custom LLM driver integrations
- **Native Laravel events support** - Support for Laravel events
- **Debug mode** -  Should log all processes happening under the hood

### Enhanced AI Capabilities 🧠
- **Prism Package Integration** - Additional LLM providers support
- **Gemini Integration** - Additional LLM provider ✔️
- **Anthropic Integration** - Additional LLM provider
- **Usage abstraction** - Abstraction for tokens usage
- **Streaming Support** - Out-of-the-box support for streaming responses ✔️
- **RAG & Knowledge Base** 
  - Built-in vector storage providers
  - Seamless document embeddings integration
  - Smart context management
- **Ready-to-use Tools** - Built-in tools as traits
- **Structured Output at runtime** - Allow defining the response JSON Schema at runtime.
- **Transfer tool** - One of the methods of agents chaining


### Security & Storage 🔒
- **Enhanced Chat History Security** - Optional encryption for sensitive conversations

### Advanced Integrations 🔌
- **Provider Fallback System** - Automatic fallback to alternative providers ✔️
- **Voice Chat Support** - Out of the box support for voice interactions with your agents

Stay tuned! We're constantly working on making LarAgent the most versatile AI agent framework for Laravel.

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

These are the contents of the published config file:

```php
return [
    'default_driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
    'default_chat_history' => \LarAgent\History\InMemoryChatHistory::class,

    'providers' => [

        'default' => [
            'label' => 'openai',
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
        'label' => 'mini',
        'model' => 'gpt-3.5-turbo',
        'api_key' => env('CUSTOM_API_KEY'),
        'api_url' => env('CUSTOM_API_URL'),
        // Default driver and chat history
        'driver' => \LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'chat_history' => \LarAgent\History\InMemoryChatHistory::class,
        'default_context_window' => 15000,
        'default_max_completion_tokens' => 100,
        'default_temperature' => 1,
        // Enable/disable parallel tool calls
        'parallel_tool_calls' => true,
        // Store metadata with messages
        'store_meta' => true,
        // Save chat keys to memory via chatHistory
        'save_chat_keys' => true,
    ],
```

Provider just gives you the defaults. Every config can be overridden per agent in agent class.


## Contributing

We welcome contributions to LarAgent! Whether it's improving documentation, fixing bugs, or adding new features, your help is appreciated. Here's how you can [contribute](https://docs.laragent.ai/development).

We aim to review all pull requests within a 2 weeks. Thank you for contributing to LarAgent!

## Getting Help

- Open an issue for bugs or feature requests
- Join discussions in existing issues
- Join community server on [Discord](https://discord.gg/NAczq2T9F8)
- Reach out to maintainers for guidance


## Testing

```bash
composer test
```

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

Thanks to these people and projects, LarAgent would not be possible without them:

-   [maestroerror](https://github.com/maestroerror)
-   [All Contributors](../../contributors)
-   [openai-php/client](https://github.com/openai-php/client)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Roadmap

Please see [Planned](#planned) for more information on the future development of LarAgent.
