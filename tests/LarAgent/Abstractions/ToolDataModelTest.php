<?php

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\LlmDriver;
use LarAgent\Tool;
use Mockery as m;

// Test DataModel fixtures
class TaskDataModel extends DataModel
{
    public string $title;

    public int $estimatedHours;

    public ?string $description = null;
}

class AddressDataModel extends DataModel
{
    public string $street;

    public string $city;

    public ?string $zipCode = null;
}

class PersonDataModel extends DataModel
{
    public string $name;

    public int $age;
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

// Tests for addDataModelAsProperties
describe('addDataModelAsProperties', function () {

    it('extracts properties from DataModel schema', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class);

        $properties = $tool->getProperties();

        expect($properties)
            ->toHaveKeys(['title', 'estimatedHours', 'description'])
            ->and($properties['title']['type'])->toBe('string')
            ->and($properties['estimatedHours']['type'])->toBe('integer');
    });

    it('extracts required fields from DataModel schema', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class);

        $required = $tool->getRequired();

        // title and estimatedHours should be required (no default value, not nullable)
        expect($required)->toContain('title')
            ->and($required)->toContain('estimatedHours')
            ->and($required)->not->toContain('description'); // has default value
    });

    it('sets root DataModel class for conversion', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class);

        expect($tool->getRootDataModelClass())->toBe(TaskDataModel::class);
    });

    it('throws exception for non-DataModel class', function () {
        Tool::create('invalid_tool', 'Invalid tool')
            ->addDataModelAsProperties(\stdClass::class);
    })->throws(\InvalidArgumentException::class, 'must implement DataModel contract');

    it('executes callback with DataModel instance when root DataModel is set', function () {
        $receivedData = null;

        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class)
            ->setCallback(function (TaskDataModel $task) use (&$receivedData) {
                $receivedData = $task;

                return "Created: {$task->title}";
            });

        $result = $tool->execute([
            'title' => 'My Task',
            'estimatedHours' => 8,
            'description' => 'Test description',
        ]);

        expect($receivedData)->toBeInstanceOf(TaskDataModel::class)
            ->and($receivedData->title)->toBe('My Task')
            ->and($receivedData->estimatedHours)->toBe(8)
            ->and($receivedData->description)->toBe('Test description')
            ->and($result)->toBe('Created: My Task');
    });

    it('generates correct schema in toArray', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class);

        $array = $tool->toArray();

        expect($array['function']['parameters']['properties'])
            ->toHaveKeys(['title', 'estimatedHours', 'description']);
    });

    it('works with DataModel instance instead of class name', function () {
        $dataModel = new TaskDataModel;

        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties($dataModel);

        expect($tool->getRootDataModelClass())->toBe(TaskDataModel::class)
            ->and($tool->getProperties())->toHaveKeys(['title', 'estimatedHours', 'description']);
    });
});

// Tests for addDataModelProperty
describe('addDataModelProperty', function () {

    it('adds a single property with DataModel schema', function () {
        $tool = Tool::create('create_task', 'Create a task with address')
            ->addProperty('name', 'string', 'Task name')
            ->addDataModelProperty('address', AddressDataModel::class);

        $properties = $tool->getProperties();

        expect($properties)->toHaveKeys(['name', 'address'])
            ->and($properties['name']['type'])->toBe('string')
            ->and($properties['address']['type'])->toBe('object')
            ->and($properties['address']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });

    it('registers DataModel for automatic conversion', function () {
        $receivedAddress = null;

        $tool = Tool::create('create_task', 'Create a task with address')
            ->addProperty('name', 'string', 'Task name')
            ->addDataModelProperty('address', AddressDataModel::class)
            ->setRequired('name')
            ->setRequired('address')
            ->setCallback(function (string $name, AddressDataModel $address) use (&$receivedAddress) {
                $receivedAddress = $address;

                return "Created: {$name} at {$address->city}";
            });

        $result = $tool->execute([
            'name' => 'My Task',
            'address' => [
                'street' => '123 Main St',
                'city' => 'New York',
                'zipCode' => '10001',
            ],
        ]);

        expect($receivedAddress)->toBeInstanceOf(AddressDataModel::class)
            ->and($receivedAddress->street)->toBe('123 Main St')
            ->and($receivedAddress->city)->toBe('New York')
            ->and($result)->toBe('Created: My Task at New York');
    });

    it('adds description to property schema', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelProperty('address', AddressDataModel::class, 'The delivery address');

        $properties = $tool->getProperties();

        expect($properties['address']['description'])->toBe('The delivery address');
    });

    it('throws exception for non-DataModel class', function () {
        Tool::create('invalid_tool', 'Invalid tool')
            ->addDataModelProperty('data', \stdClass::class);
    })->throws(\InvalidArgumentException::class, 'must implement DataModel contract');

    it('works with DataModel instance instead of class name', function () {
        $dataModel = new AddressDataModel;

        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelProperty('address', $dataModel, 'The address');

        $properties = $tool->getProperties();

        expect($properties['address']['type'])->toBe('object')
            ->and($properties['address']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });

    it('does not set root DataModel class', function () {
        $tool = Tool::create('create_task', 'Create a task')
            ->addDataModelProperty('address', AddressDataModel::class);

        expect($tool->getRootDataModelClass())->toBeNull();
    });
});

// Tests for mixed usage
describe('mixed DataModel property usage', function () {

    it('supports multiple DataModel properties', function () {
        $tool = Tool::create('create_meeting', 'Create a meeting')
            ->addProperty('title', 'string', 'Meeting title')
            ->addDataModelProperty('organizer', PersonDataModel::class, 'The organizer')
            ->addDataModelProperty('location', AddressDataModel::class, 'The location')
            ->setRequired('title')
            ->setRequired('organizer')
            ->setRequired('location');

        $properties = $tool->getProperties();

        expect($properties)->toHaveKeys(['title', 'organizer', 'location'])
            ->and($properties['organizer']['type'])->toBe('object')
            ->and($properties['organizer']['properties'])->toHaveKeys(['name', 'age'])
            ->and($properties['location']['type'])->toBe('object')
            ->and($properties['location']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });

    it('converts multiple DataModel properties on execution', function () {
        $receivedOrganizer = null;
        $receivedLocation = null;

        $tool = Tool::create('create_meeting', 'Create a meeting')
            ->addProperty('title', 'string', 'Meeting title')
            ->addDataModelProperty('organizer', PersonDataModel::class, 'The organizer')
            ->addDataModelProperty('location', AddressDataModel::class, 'The location')
            ->setRequired('title')
            ->setRequired('organizer')
            ->setRequired('location')
            ->setCallback(function (string $title, PersonDataModel $organizer, AddressDataModel $location) use (&$receivedOrganizer, &$receivedLocation) {
                $receivedOrganizer = $organizer;
                $receivedLocation = $location;

                return "Meeting: {$title}";
            });

        $tool->execute([
            'title' => 'Team Standup',
            'organizer' => ['name' => 'John', 'age' => 30],
            'location' => ['street' => '456 Office St', 'city' => 'Boston'],
        ]);

        expect($receivedOrganizer)->toBeInstanceOf(PersonDataModel::class)
            ->and($receivedOrganizer->name)->toBe('John')
            ->and($receivedLocation)->toBeInstanceOf(AddressDataModel::class)
            ->and($receivedLocation->city)->toBe('Boston');
    });
});

// Tests for backward compatibility
describe('backward compatibility', function () {

    it('still works with manual property definitions', function () {
        $tool = Tool::create('get_weather', 'Get weather')
            ->addProperty('location', 'string', 'City name')
            ->addProperty('unit', 'string', 'Temperature unit', ['celsius', 'fahrenheit'])
            ->setRequired('location')
            ->setCallback(function (string $location, string $unit = 'celsius') {
                return "Weather in {$location}: 20°{$unit}";
            });

        $result = $tool->execute(['location' => 'New York', 'unit' => 'celsius']);

        expect($result)->toBe('Weather in New York: 20°celsius');
    });

    it('still works with addDataModelType for individual parameters', function () {
        $receivedTask = null;

        $tool = Tool::create('create_task', 'Create a task')
            ->addProperty('task', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'estimatedHours' => ['type' => 'integer'],
                ],
                'required' => ['title', 'estimatedHours'],
            ])
            ->addDataModelType('task', TaskDataModel::class)
            ->setRequired('task')
            ->setCallback(function (TaskDataModel $task) use (&$receivedTask) {
                $receivedTask = $task;

                return "Created: {$task->title}";
            });

        $tool->execute([
            'task' => ['title' => 'Test Task', 'estimatedHours' => 4],
        ]);

        expect($receivedTask)->toBeInstanceOf(TaskDataModel::class)
            ->and($receivedTask->title)->toBe('Test Task');
    });
});
