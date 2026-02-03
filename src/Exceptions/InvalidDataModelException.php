<?php

namespace LarAgent\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when an invalid DataModel class is provided.
 */
class InvalidDataModelException extends InvalidArgumentException
{
    /**
     * Create exception for a class that doesn't implement DataModel contract.
     */
    public static function notADataModel(string $className): self
    {
        return new self("Class {$className} must implement DataModel contract.");
    }

    /**
     * Create exception for a class that doesn't exist.
     */
    public static function classNotFound(string $className): self
    {
        return new self("Class {$className} does not exist.");
    }

    /**
     * Create exception for an invalid $dataModelClass property value.
     */
    public static function invalidDataModelClassProperty(string $className): self
    {
        return new self(
            "The \$dataModelClass property is set to '{$className}' which does not implement DataModel contract. "
            .'Either set it to a valid DataModel class or null.'
        );
    }
}
