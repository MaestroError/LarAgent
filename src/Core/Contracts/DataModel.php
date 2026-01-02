<?php

namespace LarAgent\Core\Contracts;

interface DataModel
{
    /**
     * Convert the model to an array.
     */
    public function toArray(): array;

    /**
     * Generate the OpenAPI schema for the model.
     */
    public function toSchema(): array;

    /**
     * Fill the model with an array of attributes.
     */
    public function fill(array $attributes): static;

    /**
     * Create a new instance from an array of attributes.
     */
    public static function fromArray(array $attributes): static;
}
