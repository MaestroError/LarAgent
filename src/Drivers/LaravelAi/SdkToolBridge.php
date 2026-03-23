<?php

namespace LarAgent\Drivers\LaravelAi;

use Closure;
use LarAgent\Core\Contracts\Tool as ToolInterface;

/**
 * Bridges a LarAgent Tool to a Laravel AI SDK Tool.
 * Implements the SDK's Tool contract so LarAgent tools can be used
 * with the SDK's agent system, while preserving LarAgent's hook system.
 */
class SdkToolBridge implements \Laravel\Ai\Contracts\Tool
{
    public function __construct(
        private ToolInterface $tool,
        private ?Closure $beforeHook = null,
        private ?Closure $afterHook = null,
    ) {}

    /**
     * SDK checks method_exists($tool, 'name') for custom tool naming.
     */
    public function name(): string
    {
        return $this->tool->getName();
    }

    /**
     * Tool description for the LLM.
     */
    public function description(): string
    {
        return $this->tool->getDescription();
    }

    /**
     * Execute the tool, firing LarAgent hooks before and after.
     */
    public function handle(\Laravel\Ai\Tools\Request $request): string
    {
        $args = $request->toArray();

        // Fire LarAgent beforeToolExecution hook
        // Note: The SDK owns the tool loop, so we cannot abort it entirely.
        // Returning a cancellation message gives the LLM context about what happened.
        if ($this->beforeHook && call_user_func($this->beforeHook, $this->tool, $args) === false) {
            return json_encode(['error' => 'Tool execution was cancelled by a hook.']);
        }

        $result = $this->tool->execute($args);

        // Fire LarAgent afterToolExecution hook
        if ($this->afterHook) {
            call_user_func($this->afterHook, $this->tool, $args, $result);
        }

        return is_string($result) ? $result : json_encode($result);
    }

    /**
     * Convert LarAgent tool property definitions to SDK JsonSchema format.
     */
    public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        $properties = $this->tool->getProperties();
        $required = $this->tool->getRequired();
        $sdkSchema = [];

        foreach ($properties as $name => $property) {
            $sdkSchema[$name] = $this->convertPropertyToSchema($schema, $name, $property, $required);
        }

        return $sdkSchema;
    }

    /**
     * Convert a single LarAgent property definition to SDK schema.
     * Supports nested objects with sub-properties, typed arrays with items,
     * enum values, and required field markers.
     */
    protected function convertPropertyToSchema(
        \Illuminate\Contracts\JsonSchema\JsonSchema $schema,
        string $name,
        array $property,
        array $required
    ): mixed {
        $type = $property['type'] ?? 'string';
        $description = $property['description'] ?? '';

        // Handle union types (array of type strings → oneOf)
        if (is_array($type)) {
            $type = $type[0] ?? 'string';
        }

        $schemaField = match ($type) {
            'integer', 'int' => $schema->integer(),
            'number', 'float', 'double' => $schema->number(),
            'boolean', 'bool' => $schema->boolean(),
            'array' => $this->convertArraySchema($schema, $property),
            'object' => $this->convertObjectSchema($schema, $property),
            default => $schema->string(),
        };

        if ($description !== '') {
            $schemaField = $schemaField->description($description);
        }

        if (isset($property['enum'])) {
            $schemaField = $schemaField->enum($property['enum']);
        }

        if (in_array($name, $required)) {
            $schemaField = $schemaField->required();
        }

        return $schemaField;
    }

    /**
     * Convert an array-type property, recursively handling typed items.
     */
    protected function convertArraySchema(
        \Illuminate\Contracts\JsonSchema\JsonSchema $schema,
        array $property
    ): mixed {
        $arrayField = $schema->array();

        // If items definition exists, recurse to define item type
        if (isset($property['items'])) {
            $itemType = $property['items']['type'] ?? 'string';
            $itemSchema = match ($itemType) {
                'integer', 'int' => $schema->integer(),
                'number', 'float', 'double' => $schema->number(),
                'boolean', 'bool' => $schema->boolean(),
                'object' => $this->convertObjectSchema($schema, $property['items']),
                'array' => $this->convertArraySchema($schema, $property['items']),
                default => $schema->string(),
            };

            if (isset($property['items']['description']) && $property['items']['description'] !== '') {
                $itemSchema = $itemSchema->description($property['items']['description']);
            }

            $arrayField = $arrayField->items($itemSchema);
        }

        return $arrayField;
    }

    /**
     * Convert an object-type property, recursively handling sub-properties.
     * Sub-properties are flattened into the parent schema since the SDK's
     * JsonSchema ObjectType may not support a nested properties() call.
     */
    protected function convertObjectSchema(
        \Illuminate\Contracts\JsonSchema\JsonSchema $schema,
        array $property
    ): mixed {
        $objectField = $schema->object();

        // Recurse into sub-properties if defined and the SDK supports it
        if (isset($property['properties']) && is_array($property['properties'])) {
            $subRequired = $property['required'] ?? [];
            $subProperties = [];
            foreach ($property['properties'] as $subName => $subProp) {
                $subProperties[$subName] = $this->convertPropertyToSchema(
                    $schema,
                    $subName,
                    $subProp,
                    $subRequired
                );
            }

            // Use properties() if available, otherwise return plain object type
            if (method_exists($objectField, 'properties')) {
                $objectField = $objectField->properties($subProperties);
            }
        }

        return $objectField;
    }

    /**
     * Get the underlying LarAgent tool.
     */
    public function getLarAgentTool(): ToolInterface
    {
        return $this->tool;
    }

    /**
     * Create SDK tool bridges from an array of LarAgent tools.
     *
     * @param  array<ToolInterface>  $tools
     * @return array<self>
     */
    public static function fromLarAgentTools(array $tools, ?Closure $before = null, ?Closure $after = null): array
    {
        return array_map(
            fn (ToolInterface $tool) => new self($tool, $before, $after),
            array_values($tools)
        );
    }
}
