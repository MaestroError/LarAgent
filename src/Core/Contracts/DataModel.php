<?php

namespace LarAgent\Core\Contracts;

interface DataModel
{
    /**
     * Convert the model to an array.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Generate the OpenAPI schema for the model.
     *
     * @return array
     */
    public function toSchema(): array;

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return static
     */
    public function fill(array $attributes): static;

    /**
     * Create a new instance from an array of attributes.
     *
     * @param array $attributes
     * @return static
     */
    public static function fromArray(array $attributes): static;
}
