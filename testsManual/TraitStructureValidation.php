<?php

/**
 * Simple validation that the trait methods are accessible
 */

// Simulate autoloading for the test
spl_autoload_register(function ($class) {
    $prefix = 'LarAgent\\';
    $base_dir = __DIR__ . '/../src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

use LarAgent\Core\Traits\UsesCachedReflection;

class TestClass {
    use UsesCachedReflection;
}

echo "=== Trait Method Accessibility Test ===\n\n";

try {
    // Test that the main cache property exists (indirectly through methods)
    $reflection = new ReflectionClass(TestClass::class);
    
    // Check that key methods exist
    $methods = [
        'getCachedConfig',
        'generateSchemaFromTrait',
        'getPropertySchemaFromConfig',
        'castValue',
        'canCastToType',
        'isValueValidForType',
        'reflectionTypeToSchema',
        'unionTypeToSchema',
        'namedTypeToSchema',
        'builtinTypeToSchema',
        'enumTypeToSchema',
        'dataModelTypeToSchema',
        'typeNameToSchema',
        'getTypeInfo',
        'clearReflectionCache',
    ];
    
    $missingMethods = [];
    foreach ($methods as $method) {
        if (!$reflection->hasMethod($method)) {
            $missingMethods[] = $method;
        }
    }
    
    if (!empty($missingMethods)) {
        throw new Exception("Missing methods in trait: " . implode(', ', $missingMethods));
    }
    
    echo "✓ All expected methods present in trait\n";
    
    // Check that reflectionCache property exists
    $hasProperty = false;
    foreach ($reflection->getProperties() as $prop) {
        if ($prop->getName() === 'reflectionCache') {
            $hasProperty = true;
            break;
        }
    }
    
    if (!$hasProperty) {
        throw new Exception("Missing reflectionCache property in trait");
    }
    
    echo "✓ reflectionCache property exists\n";
    
    echo "\n=== All Validations Passed ===\n";
    echo "The trait has been successfully refactored with all reflection methods.\n";
    exit(0);
    
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
