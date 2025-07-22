<?php

use LarAgent\Core\Contracts\LlmDriver;
use LarAgent\Tests\LarAgent\Fakes\FakeWeatherService;
use LarAgent\Tool;
use Mockery as m;

// Test function
function getWeather($location)
{
    return "Weather for {$location}";
}

// Create a mock LlmDriver for testing
beforeEach(function () {
    $mockDriver = m::mock(LlmDriver::class);
    $mockDriver->shouldReceive('formatToolForPayload')
        ->andReturnUsing(function ($tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool->getProperties(),
                        'required' => $tool->getRequired(),
                    ],
                ],
            ];
        });

    app()->instance(LlmDriver::class, $mockDriver);
});

afterEach(function () {
    m::close();
});

it('can create a tool with name and description', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location');

    expect($tool->getName())->toBe('get_current_weather')
        ->and($tool->getDescription())->toBe('Get the current weather in a given location');
});

it('can add properties to a tool', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit']);

    $properties = $tool->getProperties();

    expect($properties)
        ->toHaveKeys(['location', 'unit'])
        ->and($properties['location']['type'])->toBe('string')
        ->and($properties['location']['description'])->toBe('The city and state, e.g. San Francisco, CA')
        ->and($properties['unit']['enum'])->toMatchArray(['celsius', 'fahrenheit']);
});

it('can set required properties', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->setRequired('location');

    expect($tool->toArray()['function']['parameters']['required'])
        ->toMatchArray(['location']);
});

it('throws an exception if setting required property that does not exist', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location');

    $tool->setRequired('invalid_property');
})->throws(InvalidArgumentException::class, "Property 'invalid_property' does not exist");

it('executes the callback with valid parameters and anonymous function callback', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
        ->setRequired('location')
        ->setCallback(function ($location, $unit = 'celsius') {
            return [
                'location' => $location,
                'unit' => $unit,
                'temperature' => $unit === 'celsius' ? '20°C' : '68°F',
            ];
        });

    $result = $tool->execute(['location' => 'San Francisco, CA', 'unit' => 'fahrenheit']);

    expect($result)
        ->toHaveKeys(['location', 'unit', 'temperature'])
        ->and($result['location'])->toBe('San Francisco, CA')
        ->and($result['unit'])->toBe('fahrenheit')
        ->and($result['temperature'])->toBe('68°F');
});

it('throws an exception if a required parameter is missing', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->setRequired('location')
        ->setCallback(function ($location) {
            return "Weather data for {$location}";
        });

    $tool->execute(['unit' => 'celsius']);
})->throws(InvalidArgumentException::class, 'Missing required parameter: location');

it('throws an exception if no callback is defined', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA');

    $tool->execute(['location' => 'San Francisco, CA']);
})->throws(BadMethodCallException::class, 'No callback defined for execution');

it('returns the tool definition as an array', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->addProperty('unit', 'string', 'The unit of temperature', ['celsius', 'fahrenheit'])
        ->setRequired('location');

    $definition = $tool->toArray();

    expect($definition)
        ->toHaveKey('type', 'function')
        ->and($definition['function']['name'])->toBe('get_current_weather')
        ->and($definition['function']['parameters']['properties']['location']['type'])->toBe('string')
        ->and($definition['function']['parameters']['required'])->toContain('location');
});

it('handles tools with no properties gracefully', function () {
    $tool = Tool::create('simple_tool', 'A tool with no properties');

    expect($tool->getProperties())->toBe([]);
});

// Callbacks

it('executes an object method callback', function () {
    $weatherService = new FakeWeatherService;

    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->setCallback([$weatherService, 'getWeather']);

    $result = $tool->execute(['location' => 'Boston, MA']);

    expect($result)->toBe('Weather for Boston, MA from WeatherService');
});

it('executes a static method callback', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->setCallback([FakeWeatherService::class, 'getWeatherStatic']);

    $result = $tool->execute(['location' => 'Los Angeles, CA']);

    expect($result)->toBe('Weather for Los Angeles, CA from StaticWeatherService');
});

it('executes a named function callback', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->addProperty('location', 'string', 'The city and state, e.g. San Francisco, CA')
        ->setCallback('getWeather');

    $result = $tool->execute(['location' => 'New York, NY']);

    expect($result)->toBe('Weather for New York, NY');
});

it('throws a TypeError if the callback is not callable', function () {
    $tool = Tool::create('get_current_weather', 'Get the current weather in a given location')
        ->setCallback('not_a_function'); // Invalid callback
})->throws(TypeError::class, 'must be of type ?callable, string given');
