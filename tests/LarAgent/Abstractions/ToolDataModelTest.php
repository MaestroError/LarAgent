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
            ->and($required)->not->toContain('description'); // nullable with default value
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

// Tests for $dataModelClass property pattern
describe('dataModelClass property', function () {

    it('automatically populates properties from dataModelClass in class-based tool', function () {
        // Create an anonymous class that extends Tool with $dataModelClass set
        $toolClass = new class extends Tool
        {
            protected string $name = 'create_task';

            protected string $description = 'Create a task';

            protected ?string $dataModelClass = TaskDataModel::class;

            public function execute(array $input): mixed
            {
                // This execute() overrides the parent Tool::execute(), so its automatic
                // DataModel conversion logic for callback-based tools is not used here.
                // Class-based tools that override execute() must call convertInputToDataModel()
                // themselves when they need a DataModel instance.
                return $input;
            }
        };

        expect($toolClass->getProperties())
            ->toHaveKeys(['title', 'estimatedHours', 'description'])
            ->and($toolClass->getProperties()['title']['type'])->toBe('string')
            ->and($toolClass->getProperties()['estimatedHours']['type'])->toBe('integer');

        expect($toolClass->getRequired())
            ->toContain('title')
            ->and($toolClass->getRequired())->toContain('estimatedHours');

        expect($toolClass->getRootDataModelClass())->toBe(TaskDataModel::class);
    });
});

// Tests for $properties with DataModel class names pattern
describe('properties array with DataModel class names', function () {

    it('expands DataModel class name in properties array', function () {
        $toolClass = new class extends Tool
        {
            protected string $name = 'create_with_address';

            protected string $description = 'Create something with an address';

            protected array $properties = [
                'name' => ['type' => 'string', 'description' => 'The name'],
                'address' => AddressDataModel::class,
            ];

            protected array $required = ['name', 'address'];

            public function execute(array $input): mixed
            {
                return $input;
            }
        };

        $properties = $toolClass->getProperties();

        expect($properties)->toHaveKeys(['name', 'address'])
            ->and($properties['name']['type'])->toBe('string')
            ->and($properties['address']['type'])->toBe('object')
            ->and($properties['address']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });

    it('registers DataModel for automatic conversion when using class name in properties', function () {
        $receivedAddress = null;

        // Use Tool::create with setProperties - no reflection needed, setProperties handles DataModel expansion
        $tool = Tool::create('create_with_address', 'Create something with an address');

        // setProperties now automatically processes DataModel class names
        $tool->setProperties([
            'name' => ['type' => 'string'],
            'address' => AddressDataModel::class,
        ]);

        $tool->setRequired('name');
        $tool->setRequired('address');

        // Set up a callback to test conversion
        $tool->setCallback(function (string $name, AddressDataModel $address) use (&$receivedAddress) {
            $receivedAddress = $address;

            return "Created {$name}";
        });

        $tool->execute([
            'name' => 'Test',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Boston',
            ],
        ]);

        expect($receivedAddress)->toBeInstanceOf(AddressDataModel::class)
            ->and($receivedAddress->city)->toBe('Boston');
    });

    it('supports multiple DataModel class names in properties array', function () {
        $toolClass = new class extends Tool
        {
            protected string $name = 'create_meeting';

            protected string $description = 'Create a meeting';

            protected array $properties = [
                'organizer' => PersonDataModel::class,
                'location' => AddressDataModel::class,
            ];

            protected array $required = ['organizer', 'location'];

            public function execute(array $input): mixed
            {
                return $input;
            }
        };

        $properties = $toolClass->getProperties();

        expect($properties['organizer']['type'])->toBe('object')
            ->and($properties['organizer']['properties'])->toHaveKeys(['name', 'age'])
            ->and($properties['location']['type'])->toBe('object')
            ->and($properties['location']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });

    it('mixes DataModel class names with regular property definitions', function () {
        $toolClass = new class extends Tool
        {
            protected string $name = 'mixed_tool';

            protected string $description = 'Tool with mixed property types';

            protected array $properties = [
                'title' => ['type' => 'string', 'description' => 'The title'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'address' => AddressDataModel::class,
            ];

            public function execute(array $input): mixed
            {
                return $input;
            }
        };

        $properties = $toolClass->getProperties();

        expect($properties['title']['type'])->toBe('string')
            ->and($properties['title']['description'])->toBe('The title')
            ->and($properties['priority']['enum'])->toEqual(['low', 'medium', 'high'])
            ->and($properties['address']['type'])->toBe('object')
            ->and($properties['address']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });
});

// Tests for attribute-based tool integration
describe('attribute-based tool integration', function () {

    it('attribute-based and manual tool creation produce equivalent schema for DataModel', function () {
        // Create tool manually using addDataModelProperty
        $manualTool = Tool::create('create_task', 'Create a task')
            ->addDataModelProperty('task', TaskDataModel::class, 'The task data')
            ->setRequired('task');

        // Simulate what attribute-based tool building does
        $attributeTool = Tool::create('create_task', 'Create a task');
        $schema = \LarAgent\Core\Helpers\SchemaGenerator::forDataModel(TaskDataModel::class);
        $attributeTool->addProperty('task', $schema, 'The task data');
        $attributeTool->addDataModelType('task', TaskDataModel::class);
        $attributeTool->setRequired('task');

        // Both should produce equivalent schemas
        expect($manualTool->getProperties()['task'])
            ->toEqual($attributeTool->getProperties()['task']);
    });

    it('different tool creation methods produce same execution result', function () {
        $manualResult = null;
        $attributeResult = null;
        $propertiesResult = null;

        // Method 1: Using addDataModelProperty
        $manualTool = Tool::create('create_task', 'Create a task')
            ->addDataModelProperty('task', TaskDataModel::class)
            ->setRequired('task')
            ->setCallback(function (TaskDataModel $task) use (&$manualResult) {
                $manualResult = $task;

                return $task->title;
            });

        // Method 2: Simulating attribute-based tool building
        $attributeTool = Tool::create('create_task', 'Create a task');
        $schema = \LarAgent\Core\Helpers\SchemaGenerator::forDataModel(TaskDataModel::class);
        $attributeTool->addProperty('task', $schema);
        $attributeTool->addDataModelType('task', TaskDataModel::class);
        $attributeTool->setRequired('task');
        $attributeTool->setCallback(function (TaskDataModel $task) use (&$attributeResult) {
            $attributeResult = $task;

            return $task->title;
        });

        // Method 3: Using $properties with DataModel class name
        // setProperties now automatically processes DataModel class names
        $tool3 = Tool::create('create_task', 'Create a task');
        $tool3->setProperties(['task' => TaskDataModel::class]);
        $tool3->setRequired('task');
        $tool3->setCallback(function (TaskDataModel $task) use (&$propertiesResult) {
            $propertiesResult = $task;

            return $task->title;
        });

        $input = ['task' => ['title' => 'Test Task', 'estimatedHours' => 5]];

        $result1 = $manualTool->execute($input);
        $result2 = $attributeTool->execute($input);
        $result3 = $tool3->execute($input);

        // All should return the same result
        expect($result1)->toBe('Test Task')
            ->and($result2)->toBe('Test Task')
            ->and($result3)->toBe('Test Task');

        // All should receive DataModel instances
        expect($manualResult)->toBeInstanceOf(TaskDataModel::class)
            ->and($attributeResult)->toBeInstanceOf(TaskDataModel::class)
            ->and($propertiesResult)->toBeInstanceOf(TaskDataModel::class);

        // All instances should have same data
        expect($manualResult->title)->toBe('Test Task')
            ->and($attributeResult->title)->toBe('Test Task')
            ->and($propertiesResult->title)->toBe('Test Task');
    });

    it('addDataModelAsProperties produces same schema as dataModelClass property', function () {
        // Method 1: Using addDataModelAsProperties
        $methodTool = Tool::create('create_task', 'Create a task')
            ->addDataModelAsProperties(TaskDataModel::class);

        // Method 2: Using $dataModelClass property
        $propertyTool = new class extends Tool
        {
            protected string $name = 'create_task';

            protected string $description = 'Create a task';

            protected ?string $dataModelClass = TaskDataModel::class;

            public function execute(array $input): mixed
            {
                return $input;
            }
        };

        expect($methodTool->getProperties())
            ->toEqual($propertyTool->getProperties());

        expect($methodTool->getRequired())
            ->toEqual($propertyTool->getRequired());

        expect($methodTool->getRootDataModelClass())
            ->toBe($propertyTool->getRootDataModelClass());
    });
});

// Tests for edge cases and error handling
describe('error handling and edge cases', function () {

    it('throws InvalidDataModelException for invalid dataModelClass property', function () {
        new class extends Tool
        {
            protected string $name = 'invalid_tool';

            protected string $description = 'Tool with invalid dataModelClass';

            protected ?string $dataModelClass = 'NonExistentClass';

            public function execute(array $input): mixed
            {
                return $input;
            }
        };
    })->throws(\LarAgent\Exceptions\InvalidDataModelException::class);

    it('throws InvalidDataModelException for dataModelClass that does not implement DataModel', function () {
        new class extends Tool
        {
            protected string $name = 'invalid_tool';

            protected string $description = 'Tool with class that is not a DataModel';

            protected ?string $dataModelClass = 'stdClass';

            public function execute(array $input): mixed
            {
                return $input;
            }
        };
    })->throws(\LarAgent\Exceptions\InvalidDataModelException::class);

    it('clears rootDataModelClass when addProperty is called after addDataModelAsProperties', function () {
        $tool = Tool::create('task_tool', 'Task tool')
            ->addDataModelAsProperties(TaskDataModel::class);

        expect($tool->getRootDataModelClass())->toBe(TaskDataModel::class);

        $tool->addProperty('extra', 'string', 'Extra property');

        expect($tool->getRootDataModelClass())->toBeNull();
    });

    it('clears rootDataModelClass when addDataModelProperty is called after addDataModelAsProperties', function () {
        $tool = Tool::create('task_tool', 'Task tool')
            ->addDataModelAsProperties(TaskDataModel::class);

        expect($tool->getRootDataModelClass())->toBe(TaskDataModel::class);

        $tool->addDataModelProperty('address', AddressDataModel::class);

        expect($tool->getRootDataModelClass())->toBeNull();
    });

    it('clears rootDataModelClass when setProperties is called after addDataModelAsProperties', function () {
        $tool = Tool::create('task_tool', 'Task tool')
            ->addDataModelAsProperties(TaskDataModel::class);

        expect($tool->getRootDataModelClass())->toBe(TaskDataModel::class);

        $tool->setProperties(['name' => ['type' => 'string']]);

        expect($tool->getRootDataModelClass())->toBeNull();
    });

    it('clears previous properties when addDataModelAsProperties is called multiple times', function () {
        $tool = Tool::create('task_tool', 'Task tool')
            ->addDataModelAsProperties(TaskDataModel::class);

        expect($tool->getProperties())->toHaveKeys(['title', 'estimatedHours', 'description']);

        $tool->addDataModelAsProperties(AddressDataModel::class);

        expect($tool->getProperties())->toHaveKeys(['street', 'city', 'zipCode'])
            ->and($tool->getProperties())->not->toHaveKey('title');
        expect($tool->getRootDataModelClass())->toBe(AddressDataModel::class);
    });

    it('setProperties processes DataModel class names automatically', function () {
        $tool = Tool::create('mixed_tool', 'Tool with mixed properties');

        $tool->setProperties([
            'name' => ['type' => 'string'],
            'address' => AddressDataModel::class,
        ]);

        $properties = $tool->getProperties();

        expect($properties['name']['type'])->toBe('string')
            ->and($properties['address']['type'])->toBe('object')
            ->and($properties['address']['properties'])->toHaveKeys(['street', 'city', 'zipCode']);
    });
});

// Tests for class-based tools with convertInputToDataModel
describe('class-based tools with convertInputToDataModel', function () {

    it('provides convertInputToDataModel method for class-based tools', function () {
        $toolClass = new class extends Tool
        {
            protected string $name = 'create_task';

            protected string $description = 'Create a task';

            protected ?string $dataModelClass = TaskDataModel::class;

            public function execute(array $input): mixed
            {
                // Use convertInputToDataModel to get the DataModel instance
                $task = $this->convertInputToDataModel($input);

                return $task;
            }
        };

        $result = $toolClass->execute([
            'title' => 'Test Task',
            'estimatedHours' => 5,
        ]);

        expect($result)->toBeInstanceOf(TaskDataModel::class)
            ->and($result->title)->toBe('Test Task')
            ->and($result->estimatedHours)->toBe(5);
    });

    it('convertInputToDataModel returns array when rootDataModelClass is not set', function () {
        $toolClass = new class extends Tool
        {
            protected string $name = 'simple_tool';

            protected string $description = 'Simple tool without DataModel';

            protected array $properties = [
                'name' => ['type' => 'string'],
            ];

            public function execute(array $input): mixed
            {
                return $this->convertInputToDataModel($input);
            }
        };

        $result = $toolClass->execute(['name' => 'Test']);

        expect($result)->toBeArray()
            ->and($result['name'])->toBe('Test');
    });
});
