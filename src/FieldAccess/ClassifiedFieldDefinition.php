<?php

declare(strict_types=1);

namespace App\FieldAccess;

use Symfony\Component\Validator\Constraint;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldReadDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * Adds an application-reviewed read level without changing the field's
 * persistence, validation, translation, or import metadata.
 */
final readonly class ClassifiedFieldDefinition implements FieldDefinitionInterface, FieldReadDefinitionInterface
{
    public function __construct(
        private FieldDefinitionInterface $inner,
        private FieldReadLevel $readLevel,
    ) {}

    public function getReadLevel(): ?FieldReadLevel
    {
        return $this->readLevel;
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getType(): string
    {
        return $this->inner->getType();
    }

    public function getCardinality(): int
    {
        return $this->inner->getCardinality();
    }

    public function isMultiple(): bool
    {
        return $this->inner->isMultiple();
    }

    public function getSettings(): array
    {
        return $this->inner->getSettings();
    }

    public function getSetting(string $name): mixed
    {
        return $this->inner->getSetting($name);
    }

    public function getTargetEntityTypeId(): string
    {
        return $this->inner->getTargetEntityTypeId();
    }

    public function getTargetBundle(): ?string
    {
        return $this->inner->getTargetBundle();
    }

    public function isTranslatable(): bool
    {
        return $this->inner->isTranslatable();
    }

    public function isRevisionable(): bool
    {
        return $this->inner->isRevisionable();
    }

    public function getDefaultValue(): mixed
    {
        return $this->inner->getDefaultValue();
    }

    public function toJsonSchema(): array
    {
        return $this->inner->toJsonSchema();
    }

    public function getStored(): FieldStorage
    {
        return $this->inner->getStored();
    }

    public function getGroup(): string
    {
        return $this->inner->getGroup();
    }

    public function getPromptAliases(): array
    {
        return $this->inner->getPromptAliases();
    }

    public function getDataType(): string
    {
        return $this->inner->getDataType();
    }

    public function getLabel(): string
    {
        return $this->inner->getLabel();
    }

    public function getDescription(): string
    {
        return $this->inner->getDescription();
    }

    public function isRequired(): bool
    {
        return $this->inner->isRequired();
    }

    public function isReadOnly(): bool
    {
        return $this->inner->isReadOnly();
    }

    public function isList(): bool
    {
        return $this->inner->isList();
    }

    /** @return Constraint[] */
    public function getConstraints(): array
    {
        return $this->inner->getConstraints();
    }
}
