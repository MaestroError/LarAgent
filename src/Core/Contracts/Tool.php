<?php

namespace LarAgent\Core\Contracts;

interface Tool
{
    /**
     * Get the unique name of the tool.
     */
    public function getName(): string;

    /**
     * Get a description of the tool's functionality.
     */
    public function getDescription(): string;

    /**
     * Add a Property with a name, type, description, and optional enum values.
     */
    public function addProperty(string $name, string $type, string $description = '', array $enum = []): self;

    /**
     * Set properties array.
     */
    public function setProperties(array $props): self;

    /**
     * Mark a Property as required.
     */
    public function setRequired(string $name): self;

    /**
     * Set array of required properties.
     */
    public function setRequiredProps(array $required): self;

    /**
     * Get the Properties schema.
     */
    public function getProperties(): array;

    /**
     * Get the required properties.
     */
    public function getRequired(): array;

    /**
     * Generate the complete tool definition as an array.
     */
    public function toArray(): array;

    /**
     * Get the metadata for the tool.
     */
    public function getMetaData(): array;

    public function setMetaData(array $metaData): self;

    /**
     * Execute the tool's logic with input parameters.
     */
    public function execute(array $input): mixed;
}
