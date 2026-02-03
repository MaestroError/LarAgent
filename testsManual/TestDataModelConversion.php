<?php
/**
 * Quick test to verify DataModel conversion behavior with handle() method
 */
require_once __DIR__.'/../vendor/autoload.php';

use LarAgent\Core\Abstractions\DataModel;
use LarAgent\Core\Contracts\DataModel as DataModelContract;
use LarAgent\Tool;

class PersonDM extends DataModel {
    public string $name;
    public int $age;
}

// Test 1: Class-based tool using NEW handle() pattern (recommended)
echo "Test 1: Class-based tool using handle() - RECOMMENDED PATTERN\n";

class TestToolWithHandle extends Tool {
    protected string $name = "test_tool";
    protected string $description = "Test";
    protected array $properties = [
        "title" => ["type" => "string"],
        "person" => PersonDM::class,
    ];
    protected array $required = ["title", "person"];

    protected function handle(array|DataModelContract $input): mixed {
        // DataModel properties are automatically converted!
        $person = $input["person"];
        echo "  Type received: " . gettype($person) . "\n";
        if ($person instanceof PersonDM) {
            echo "  ✓ Auto-converted to: " . get_class($person) . "\n";
            echo "  Name: {$person->name}, Age: {$person->age}\n";
        } else {
            echo "  ✗ Not converted\n";
        }
        return "done";
    }
}

$tool = new TestToolWithHandle();
$tool->execute([
    "title" => "Test",
    "person" => ["name" => "Jane", "age" => 25]
]);

// Test 2: OLD pattern - still works for backward compatibility
echo "\nTest 2: Class-based tool using execute() - STILL WORKS (backward compat)\n";

class TestToolWithExecute extends Tool {
    protected string $name = "test_tool2";
    protected string $description = "Test";
    protected array $properties = [
        "title" => ["type" => "string"],
        "person" => PersonDM::class,
    ];
    protected array $required = ["title", "person"];

    // Old pattern: override execute() directly
    public function execute(array $input): mixed {
        // Note: This bypasses the automatic conversion!
        $person = $input["person"];
        echo "  Type received: " . gettype($person) . "\n";
        if (is_array($person)) {
            echo "  ⚠ Still an array (old pattern - no auto-conversion)\n";
        }
        return "done";
    }
}

$tool2 = new TestToolWithExecute();
$tool2->execute([
    "title" => "Test",
    "person" => ["name" => "John", "age" => 30]
]);

// Test 3: Callback-based tool (unchanged - still works automatically)
echo "\nTest 3: Callback-based tool - automatic conversion\n";

$tool3 = Tool::create("test_callback", "Test")
    ->addProperty("title", "string")
    ->addDataModelProperty("person", PersonDM::class)
    ->setRequired("title")
    ->setRequired("person")
    ->setCallback(function(string $title, $person) {
        echo "  Type received: " . gettype($person) . "\n";
        if ($person instanceof PersonDM) {
            echo "  ✓ Auto-converted to: " . get_class($person) . "\n";
        }
        return "done";
    });

$tool3->execute([
    "title" => "Test",
    "person" => ["name" => "Alice", "age" => 28]
]);

// Test 4: Class-based tool with $dataModelClass (entire input as DataModel)
echo "\nTest 4: Class-based tool with \$dataModelClass - receives DataModel directly\n";

class TaskDM extends DataModel {
    public string $title;
    public int $hours;
}

class CreateTaskTool extends Tool {
    protected string $name = "create_task";
    protected string $description = "Create a task";
    protected ?string $dataModelClass = TaskDM::class;

    protected function handle(array|DataModelContract $input): mixed {
        // With $dataModelClass, entire input is automatically converted to DataModel!
        /** @var TaskDM $task */
        $task = $input;
        echo "  Type received: " . get_class($task) . "\n";
        echo "  ✓ Title: {$task->title}, Hours: {$task->hours}\n";
        return "done";
    }
}

$tool4 = new CreateTaskTool();
$tool4->execute([
    "title" => "Build feature",
    "hours" => 8
]);

echo "\n=== Summary ===\n";
echo "For class-based tools, override handle() instead of execute()\n";
echo "to get automatic DataModel/Enum conversion.\n";
echo "\n";
echo "When using \$dataModelClass: handle() receives the DataModel instance directly.\n";
echo "When using \$properties with DataModel: handle() receives array with converted properties.\n";
echo "Old tools that override execute() still work (backward compatible).\n";
