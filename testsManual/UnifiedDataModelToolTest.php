<?php

/**
 * Manual Test: Unified DataModel Property Support for Tool Creation
 *
 * Tests the PR #141 claim: "Unify DataModel property support for all Tool creation mechanisms"
 *
 * This test verifies:
 * 1. Facade-based tools with addDataModelAsProperties()
 * 2. Facade-based tools with addDataModelProperty()
 * 3. Class-based tools with $dataModelClass property
 * 4. Class-based tools with $properties = ['key' => DataModel::class]
 * 5. Backward compatibility with existing patterns
 * 6. Integration with real LLM API (OpenAI)
 *
 * Run with: php testsManual/UnifiedDataModelToolTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Agent;
use LarAgent\Attributes\Desc;
use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Exceptions\InvalidDataModelException;
use LarAgent\Tool;

// Configuration function
function config(string $key): mixed
{
    $yourApiKey = include __DIR__.'/openai-api-key.php';

    $config = [
        'laragent.default_driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
        'laragent.default_chat_history' => LarAgent\History\InMemoryChatHistory::class,
        'laragent.fallback_provider' => null,
        'laragent.track_usage' => false,
        'laragent.enable_truncation' => false,
        'laragent.enable_summarization' => false,
        'laragent.enable_symbolization' => false,
        'laragent.providers.default' => [
            'label' => 'default',
            'api_key' => $yourApiKey,
            'driver' => LarAgent\Drivers\OpenAi\OpenAiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 2000,
            'default_temperature' => 0.3,
            'model' => 'gpt-4o-mini',
            'track_usage' => false,
            'enable_truncation' => false,
            'enable_summarization' => false,
            'enable_symbolization' => false,
        ],
    ];

    return $config[$key] ?? null;
}

// ============================================================================
// DataModel Fixtures
// ============================================================================

/**
 * Simple DataModel for task creation
 */
class TaskDataModel extends DataModel
{
    #[Desc('The title of the task')]
    public string $title;

    #[Desc('Estimated hours to complete')]
    public int $estimatedHours;

    #[Desc('Optional description')]
    public ?string $description = null;
}

/**
 * DataModel for address
 */
class AddressDataModel extends DataModel
{
    #[Desc('Street address')]
    public string $street;

    #[Desc('City name')]
    public string $city;

    #[Desc('Optional zip code')]
    public ?string $zipCode = null;
}

/**
 * DataModel for a person
 */
class PersonDataModel extends DataModel
{
    #[Desc('Full name of the person')]
    public string $name;

    #[Desc('Age in years')]
    public int $age;
}

// ============================================================================
// Class-based Tool with $dataModelClass property
// ============================================================================

class CreateTaskTool extends Tool
{
    protected string $name = 'create_task';

    protected string $description = 'Create a new task from the provided task data';

    protected ?string $dataModelClass = TaskDataModel::class;

    protected function handle(array|DataModelContract $input): mixed
    {
        // With $dataModelClass, the entire input is automatically converted to a DataModel instance
        /** @var TaskDataModel $task */
        $task = $input;
        $result = "Task '{$task->title}' created with {$task->estimatedHours} hours estimate.";
        if ($task->description) {
            $result .= " Description: {$task->description}";
        }

        return $result;
    }
}

// ============================================================================
// Class-based Tool with $properties array containing DataModel class names
// ============================================================================

class ScheduleMeetingTool extends Tool
{
    protected string $name = 'schedule_meeting';

    protected string $description = 'Schedule a meeting with a person at a location';

    protected array $properties = [
        'meetingTitle' => ['type' => 'string', 'description' => 'Title of the meeting'],
        'attendee' => PersonDataModel::class,
        'location' => AddressDataModel::class,
    ];

    protected array $required = ['meetingTitle', 'attendee', 'location'];

    protected function handle(array|DataModelContract $input): mixed
    {
        // DataModel properties are automatically converted!
        $title = $input['meetingTitle'];
        $attendee = $input['attendee'];  // PersonDataModel instance
        $location = $input['location'];  // AddressDataModel instance

        return "Meeting '{$title}' scheduled with {$attendee->name} at {$location->city}";
    }
}

// ============================================================================
// Traditional Tool for backward compatibility test
// ============================================================================

class TraditionalTool extends Tool
{
    protected string $name = 'get_weather';

    protected string $description = 'Get the current weather in a location';

    protected array $properties = [
        'location' => ['type' => 'string', 'description' => 'City and state'],
        'unit' => ['type' => 'string', 'description' => 'Temperature unit', 'enum' => ['celsius', 'fahrenheit']],
    ];

    protected array $required = ['location'];

    protected function handle(array|DataModelContract $input): mixed
    {
        $location = $input['location'];
        $unit = $input['unit'] ?? 'celsius';

        return "Weather in {$location}: 22°{$unit}, sunny";
    }
}

// ============================================================================
// Test Agents
// ============================================================================

/**
 * Agent using facade-based tool with addDataModelAsProperties
 */
class FacadeDataModelAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        // Facade pattern with addDataModelAsProperties (entire input as DataModel)
        $createTaskTool = Tool::create('create_task', 'Create a new task')
            ->addDataModelAsProperties(TaskDataModel::class)
            ->setCallback(function (TaskDataModel $task) {
                return "Task '{$task->title}' created with {$task->estimatedHours} hours.";
            });

        return [$createTaskTool];
    }

    public function instructions(): string
    {
        return 'You are a task management assistant. When the user asks to create a task, use the create_task tool with appropriate details.';
    }
}

/**
 * Agent using facade-based tool with addDataModelProperty
 */
class FacadeDataModelPropertyAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        // Facade pattern with addDataModelProperty (individual DataModel properties)
        $scheduleMeetingTool = Tool::create('schedule_meeting', 'Schedule a meeting')
            ->addProperty('title', 'string', 'Meeting title')
            ->addDataModelProperty('attendee', PersonDataModel::class, 'The person attending')
            ->addDataModelProperty('location', AddressDataModel::class, 'Meeting location')
            ->setRequired('title')
            ->setRequired('attendee')
            ->setRequired('location')
            ->setCallback(function (string $title, PersonDataModel $attendee, AddressDataModel $location) {
                return "Meeting '{$title}' scheduled with {$attendee->name} (age {$attendee->age}) at {$location->street}, {$location->city}";
            });

        return [$scheduleMeetingTool];
    }

    public function instructions(): string
    {
        return 'You are a meeting scheduler assistant. When asked to schedule a meeting, use the schedule_meeting tool.';
    }
}

/**
 * Agent using class-based tools with $dataModelClass property
 */
class ClassBasedDataModelAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        return [
            new CreateTaskTool,
        ];
    }

    public function instructions(): string
    {
        return 'You are a task management assistant. Use the create_task tool when asked to create tasks.';
    }
}

/**
 * Agent using class-based tools with $properties array containing DataModel class names
 */
class ClassBasedPropertiesArrayAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        return [
            new ScheduleMeetingTool,
        ];
    }

    public function instructions(): string
    {
        return 'You are a meeting scheduler assistant. Use the schedule_meeting tool when asked to schedule meetings.';
    }
}

/**
 * Agent using traditional tools for backward compatibility
 */
class BackwardCompatibleAgent extends Agent
{
    protected $provider = 'default';

    protected $model = 'gpt-4o-mini';

    protected $history = 'in_memory';

    public function registerTools(): array
    {
        return [new TraditionalTool];
    }

    public function instructions(): string
    {
        return 'You are a weather assistant. Use the get_weather tool when asked about weather.';
    }
}

// ============================================================================
// Test Runner
// ============================================================================

echo "=== Unified DataModel Tool Support Test (PR #141) ===\n\n";

$passed = 0;
$failed = 0;
$skipped = 0;

function runTest(string $name, callable $test): void
{
    global $passed, $failed;
    echo "Test: {$name}\n";
    try {
        $test();
        echo "  ✓ PASSED\n\n";
        $passed++;
    } catch (Exception $e) {
        echo "  ✗ FAILED: {$e->getMessage()}\n";
        echo "  Trace: ".str_replace("\n", "\n  ", $e->getTraceAsString())."\n\n";
        $failed++;
    }
}

function skipTest(string $name, string $reason): void
{
    global $skipped;
    echo "Test: {$name}\n";
    echo "  ⊘ SKIPPED: {$reason}\n\n";
    $skipped++;
}

// ----------------------------------------------------------------------------
// Unit Tests (no API calls)
// ----------------------------------------------------------------------------

echo "--- Unit Tests (No API calls) ---\n\n";

runTest('1. Facade tool with addDataModelAsProperties generates correct schema', function () {
    $tool = Tool::create('create_task', 'Create a task')
        ->addDataModelAsProperties(TaskDataModel::class);

    $properties = $tool->getProperties();

    if (! isset($properties['title']) || $properties['title']['type'] !== 'string') {
        throw new Exception('Missing or invalid title property');
    }
    if (! isset($properties['estimatedHours']) || $properties['estimatedHours']['type'] !== 'integer') {
        throw new Exception('Missing or invalid estimatedHours property');
    }
    if (! isset($properties['description'])) {
        throw new Exception('Missing description property');
    }

    $required = $tool->getRequired();
    if (! in_array('title', $required)) {
        throw new Exception('title should be required');
    }
    if (! in_array('estimatedHours', $required)) {
        throw new Exception('estimatedHours should be required');
    }
    if (in_array('description', $required)) {
        throw new Exception('description should not be required (it is nullable with default)');
    }

    if ($tool->getRootDataModelClass() !== TaskDataModel::class) {
        throw new Exception('rootDataModelClass should be set');
    }
});

runTest('2. Facade tool with addDataModelAsProperties executes callback with DataModel instance', function () {
    $receivedTask = null;

    $tool = Tool::create('create_task', 'Create a task')
        ->addDataModelAsProperties(TaskDataModel::class)
        ->setCallback(function (TaskDataModel $task) use (&$receivedTask) {
            $receivedTask = $task;

            return "Created: {$task->title}";
        });

    $result = $tool->execute([
        'title' => 'Test Task',
        'estimatedHours' => 5,
        'description' => 'A test task',
    ]);

    if (! $receivedTask instanceof TaskDataModel) {
        throw new Exception('Callback should receive TaskDataModel instance');
    }
    if ($receivedTask->title !== 'Test Task') {
        throw new Exception('TaskDataModel title mismatch');
    }
    if ($receivedTask->estimatedHours !== 5) {
        throw new Exception('TaskDataModel estimatedHours mismatch');
    }
    if ($result !== 'Created: Test Task') {
        throw new Exception('Result mismatch');
    }
});

runTest('3. Facade tool with addDataModelProperty generates correct schema', function () {
    $tool = Tool::create('schedule_meeting', 'Schedule a meeting')
        ->addProperty('title', 'string', 'Meeting title')
        ->addDataModelProperty('attendee', PersonDataModel::class, 'The attendee');

    $properties = $tool->getProperties();

    if (! isset($properties['title']) || $properties['title']['type'] !== 'string') {
        throw new Exception('Missing or invalid title property');
    }
    if (! isset($properties['attendee']) || $properties['attendee']['type'] !== 'object') {
        throw new Exception('Missing or invalid attendee property (should be object)');
    }
    if (! isset($properties['attendee']['properties']['name'])) {
        throw new Exception('attendee should have nested name property');
    }
    if (! isset($properties['attendee']['properties']['age'])) {
        throw new Exception('attendee should have nested age property');
    }
    if ($properties['attendee']['description'] !== 'The attendee') {
        throw new Exception('attendee description not set correctly');
    }

    // Should NOT set rootDataModelClass
    if ($tool->getRootDataModelClass() !== null) {
        throw new Exception('rootDataModelClass should be null for addDataModelProperty');
    }
});

runTest('4. Facade tool with addDataModelProperty converts property to DataModel on execute', function () {
    $receivedAttendee = null;

    $tool = Tool::create('schedule_meeting', 'Schedule a meeting')
        ->addProperty('title', 'string', 'Meeting title')
        ->addDataModelProperty('attendee', PersonDataModel::class, 'The attendee')
        ->setRequired('title')
        ->setRequired('attendee')
        ->setCallback(function (string $title, PersonDataModel $attendee) use (&$receivedAttendee) {
            $receivedAttendee = $attendee;

            return "Scheduled: {$title} with {$attendee->name}";
        });

    $result = $tool->execute([
        'title' => 'Team Sync',
        'attendee' => ['name' => 'John Doe', 'age' => 30],
    ]);

    if (! $receivedAttendee instanceof PersonDataModel) {
        throw new Exception('Callback should receive PersonDataModel instance');
    }
    if ($receivedAttendee->name !== 'John Doe') {
        throw new Exception('PersonDataModel name mismatch');
    }
    if ($result !== 'Scheduled: Team Sync with John Doe') {
        throw new Exception('Result mismatch');
    }
});

runTest('5. Class-based tool with $dataModelClass generates correct schema', function () {
    $tool = new CreateTaskTool;

    $properties = $tool->getProperties();

    if (! isset($properties['title'])) {
        throw new Exception('Missing title property');
    }
    if (! isset($properties['estimatedHours'])) {
        throw new Exception('Missing estimatedHours property');
    }
    if ($tool->getRootDataModelClass() !== TaskDataModel::class) {
        throw new Exception('rootDataModelClass should be set');
    }
});

runTest('6. Class-based tool with $dataModelClass converts input via convertInputToDataModel', function () {
    $tool = new CreateTaskTool;

    $result = $tool->execute([
        'title' => 'Fix Bug',
        'estimatedHours' => 2,
        'description' => 'Critical fix needed',
    ]);

    // CreateTaskTool calls convertInputToDataModel() and uses the DataModel
    if (strpos($result, 'Fix Bug') === false) {
        throw new Exception('Result should contain task title');
    }
    if (strpos($result, '2 hours') === false) {
        throw new Exception('Result should contain estimated hours');
    }
});

runTest('7. Class-based tool with $properties array containing DataModel class expands schema', function () {
    $tool = new ScheduleMeetingTool;

    $properties = $tool->getProperties();

    if (! isset($properties['meetingTitle'])) {
        throw new Exception('Missing meetingTitle property');
    }
    if (! isset($properties['attendee']) || $properties['attendee']['type'] !== 'object') {
        throw new Exception('attendee should be expanded to object schema');
    }
    if (! isset($properties['attendee']['properties']['name'])) {
        throw new Exception('attendee should have nested name property');
    }
    if (! isset($properties['location']) || $properties['location']['type'] !== 'object') {
        throw new Exception('location should be expanded to object schema');
    }
    if (! isset($properties['location']['properties']['city'])) {
        throw new Exception('location should have nested city property');
    }
});

runTest('7b. Class-based tool with handle() auto-converts DataModel properties', function () {
    $tool = new ScheduleMeetingTool;

    $result = $tool->execute([
        'meetingTitle' => 'Team Sync',
        'attendee' => ['name' => 'John', 'age' => 30],
        'location' => ['street' => '123 Main St', 'city' => 'Boston'],
    ]);

    // handle() receives automatically converted DataModel instances
    if (strpos($result, 'John') === false) {
        throw new Exception('Result should contain attendee name');
    }
    if (strpos($result, 'Boston') === false) {
        throw new Exception('Result should contain location city');
    }
});

runTest('8. setProperties() auto-processes DataModel class names', function () {
    $tool = Tool::create('test_tool', 'Test')
        ->setProperties([
            'name' => ['type' => 'string'],
            'address' => AddressDataModel::class,
        ]);

    $properties = $tool->getProperties();

    if ($properties['address']['type'] !== 'object') {
        throw new Exception('address should be expanded to object');
    }
    if (! isset($properties['address']['properties']['city'])) {
        throw new Exception('address should have nested city property');
    }
});

runTest('9. addDataModelAsProperties clears previous properties', function () {
    $tool = Tool::create('test_tool', 'Test')
        ->addProperty('oldProp', 'string', 'Will be cleared')
        ->addDataModelAsProperties(TaskDataModel::class);

    $properties = $tool->getProperties();

    if (isset($properties['oldProp'])) {
        throw new Exception('Old properties should be cleared');
    }
    if (! isset($properties['title'])) {
        throw new Exception('New DataModel properties should be set');
    }
});

runTest('10. addProperty clears rootDataModelClass', function () {
    $tool = Tool::create('test_tool', 'Test')
        ->addDataModelAsProperties(TaskDataModel::class)
        ->addProperty('extraProp', 'string', 'Extra property');

    if ($tool->getRootDataModelClass() !== null) {
        throw new Exception('rootDataModelClass should be cleared after addProperty');
    }
});

runTest('11. addDataModelProperty clears rootDataModelClass', function () {
    $tool = Tool::create('test_tool', 'Test')
        ->addDataModelAsProperties(TaskDataModel::class)
        ->addDataModelProperty('extraPerson', PersonDataModel::class);

    if ($tool->getRootDataModelClass() !== null) {
        throw new Exception('rootDataModelClass should be cleared after addDataModelProperty');
    }
});

runTest('12. Invalid DataModel class throws InvalidDataModelException', function () {
    try {
        Tool::create('test_tool', 'Test')
            ->addDataModelAsProperties(\stdClass::class);
        throw new Exception('Should have thrown InvalidDataModelException');
    } catch (InvalidDataModelException $e) {
        // Expected
        if (strpos($e->getMessage(), 'must implement DataModel contract') === false) {
            throw new Exception('Exception message should mention DataModel contract');
        }
    }
});

runTest('13. Invalid $dataModelClass property throws InvalidDataModelException', function () {
    try {
        $tool = new class extends Tool
        {
            protected string $name = 'invalid_tool';

            protected string $description = 'Invalid tool';

            protected ?string $dataModelClass = \stdClass::class;
        };
        throw new Exception('Should have thrown InvalidDataModelException');
    } catch (InvalidDataModelException $e) {
        // Expected
        if (strpos($e->getMessage(), 'does not implement DataModel contract') === false) {
            throw new Exception('Exception message should mention DataModel contract');
        }
    }
});

runTest('14. Backward compatibility: Traditional tool with array properties works', function () {
    $tool = new TraditionalTool;

    $result = $tool->execute([
        'location' => 'Boston, MA',
        'unit' => 'celsius',
    ]);

    if (strpos($result, 'Boston') === false) {
        throw new Exception('Traditional tool should work as before');
    }
});

runTest('15. Backward compatibility: Tool::create with manual properties works', function () {
    $tool = Tool::create('get_weather', 'Get weather')
        ->addProperty('location', 'string', 'City')
        ->addProperty('unit', 'string', 'Unit', ['celsius', 'fahrenheit'])
        ->setRequired('location')
        ->setCallback(function (string $location, string $unit = 'celsius') {
            return "Weather in {$location}: 20°{$unit}";
        });

    $result = $tool->execute(['location' => 'NYC', 'unit' => 'fahrenheit']);

    if ($result !== 'Weather in NYC: 20°fahrenheit') {
        throw new Exception('Manual property tool should work as before');
    }
});

runTest('16. Multiple DataModel properties work together', function () {
    $receivedPerson = null;
    $receivedAddress = null;

    $tool = Tool::create('test', 'Test')
        ->addProperty('title', 'string')
        ->addDataModelProperty('person', PersonDataModel::class)
        ->addDataModelProperty('address', AddressDataModel::class)
        ->setRequired('title')
        ->setRequired('person')
        ->setRequired('address')
        ->setCallback(function (string $title, PersonDataModel $person, AddressDataModel $address) use (&$receivedPerson, &$receivedAddress) {
            $receivedPerson = $person;
            $receivedAddress = $address;

            return "{$title}: {$person->name} @ {$address->city}";
        });

    $result = $tool->execute([
        'title' => 'Event',
        'person' => ['name' => 'Jane', 'age' => 25],
        'address' => ['street' => '123 Main', 'city' => 'Boston'],
    ]);

    if (! $receivedPerson instanceof PersonDataModel) {
        throw new Exception('person should be PersonDataModel');
    }
    if (! $receivedAddress instanceof AddressDataModel) {
        throw new Exception('address should be AddressDataModel');
    }
    if ($result !== 'Event: Jane @ Boston') {
        throw new Exception('Result mismatch');
    }
});

runTest('17. DataModel with instance works same as class name', function () {
    $tool1 = Tool::create('test1', 'Test')
        ->addDataModelAsProperties(TaskDataModel::class);

    $tool2 = Tool::create('test2', 'Test')
        ->addDataModelAsProperties(new TaskDataModel);

    // Both should have same properties
    $props1 = $tool1->getProperties();
    $props2 = $tool2->getProperties();

    if (array_keys($props1) !== array_keys($props2)) {
        throw new Exception('Properties should be identical');
    }

    // Both should have same rootDataModelClass
    if ($tool1->getRootDataModelClass() !== $tool2->getRootDataModelClass()) {
        throw new Exception('rootDataModelClass should be identical');
    }
});

// ----------------------------------------------------------------------------
// Integration Tests (with real API calls)
// ----------------------------------------------------------------------------

echo "--- Integration Tests (Real API calls) ---\n\n";

// Check if API key is available
$apiKeyFile = __DIR__.'/openai-api-key.php';
$hasApiKey = file_exists($apiKeyFile);

if (! $hasApiKey) {
    skipTest('18. Facade tool with addDataModelAsProperties works with real API', 'No API key file');
    skipTest('19. Facade tool with addDataModelProperty works with real API', 'No API key file');
    skipTest('20. Class-based tool with $dataModelClass works with real API', 'No API key file');
    skipTest('21. Class-based tool with $properties array works with real API', 'No API key file');
    skipTest('22. Backward compatible agent works with real API', 'No API key file');
} else {
    runTest('18. Facade tool with addDataModelAsProperties works with real API', function () {
        $agent = FacadeDataModelAgent::for('test_facade_datamodel');

        $response = $agent->respond('Create a task called "Review PR #141" with 2 hours estimate and description "Test the unified DataModel support"');

        echo "  Response: {$response}\n";

        // The response should mention the task was created
        if (stripos($response, 'review') === false && stripos($response, 'pr') === false && stripos($response, 'task') === false) {
            throw new Exception('Response should mention the created task');
        }
    });

    runTest('19. Facade tool with addDataModelProperty works with real API', function () {
        $agent = FacadeDataModelPropertyAgent::for('test_facade_property');

        $response = $agent->respond('Schedule a meeting called "Code Review" with John (age 30) at 100 Tech Street, San Francisco');

        echo "  Response: {$response}\n";

        // The response should mention the meeting was scheduled
        if (stripos($response, 'meeting') === false && stripos($response, 'schedul') === false && stripos($response, 'john') === false) {
            throw new Exception('Response should mention the scheduled meeting');
        }
    });

    runTest('20. Class-based tool with $dataModelClass works with real API', function () {
        $agent = ClassBasedDataModelAgent::for('test_class_datamodel');

        $response = $agent->respond('Create a task titled "Deploy to production" with 4 hours estimate');

        echo "  Response: {$response}\n";

        // The response should mention the task
        if (stripos($response, 'deploy') === false && stripos($response, 'production') === false && stripos($response, 'task') === false) {
            throw new Exception('Response should mention the created task');
        }
    });

    runTest('21. Class-based tool with $properties array works with real API', function () {
        $agent = ClassBasedPropertiesArrayAgent::for('test_class_properties');

        $response = $agent->respond('Schedule a meeting called "Sprint Planning" with Alice (age 28) at 50 Office Lane, Boston');

        echo "  Response: {$response}\n";

        // The response should mention the meeting
        if (stripos($response, 'sprint') === false && stripos($response, 'planning') === false && stripos($response, 'meeting') === false) {
            throw new Exception('Response should mention the scheduled meeting');
        }
    });

    runTest('22. Backward compatible agent works with real API', function () {
        $agent = BackwardCompatibleAgent::for('test_backward_compat');

        $response = $agent->respond("What's the weather in Boston?");

        echo "  Response: {$response}\n";

        // The response should mention Boston weather
        if (stripos($response, 'boston') === false) {
            throw new Exception('Response should mention Boston');
        }
    });
}

// ----------------------------------------------------------------------------
// Summary
// ----------------------------------------------------------------------------

echo "=== Test Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Skipped: {$skipped}\n\n";

if ($failed > 0) {
    echo "❌ Some tests failed!\n";
    exit(1);
} else {
    echo "✅ All tests passed!\n";
    exit(0);
}
