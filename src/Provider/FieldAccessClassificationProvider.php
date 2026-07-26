<?php

declare(strict_types=1);

namespace App\Provider;

use App\FieldAccess\ClassifiedFieldDefinition;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldReadDefinitionInterface;

/**
 * Activates the reviewed host classifications for package fields that predate
 * Waaseyaa's explicit field-read metadata.
 *
 * The alpha.11 Anokii entities contain public website/search content but leave
 * their read level unset. Alpha.274 correctly treats those fields as Internal.
 * This boot-time overlay applies the same checked classifications used by the
 * production preflight artifact while preserving every other field property.
 */
final class FieldAccessClassificationProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $registry = $this->resolve(FieldDefinitionRegistryInterface::class);
        if (!$registry instanceof FieldDefinitionRegistryInterface) {
            throw new \RuntimeException('Field classification requires the framework field registry.');
        }

        $path = $this->projectRoot . '/.waaseyaa/field-access-classification.json';
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $entries = $document['fields'] ?? null;
        if (!is_array($entries)) {
            throw new \RuntimeException('Field-access classification document is missing its fields map.');
        }

        /** @var array<string, array<string, FieldReadLevel>> $byEntityType */
        $byEntityType = [];
        foreach ($entries as $key => $level) {
            if (!is_string($key) || !is_string($level)) {
                throw new \RuntimeException('Field-access classifications must map string keys to string levels.');
            }
            $parts = explode('|', $key);
            if (count($parts) !== 3 || $parts[1] !== '*') {
                throw new \RuntimeException(sprintf('Unsupported field-access classification key "%s".', $key));
            }
            $byEntityType[$parts[0]][$parts[2]] = FieldReadLevel::from($level);
        }

        foreach ($byEntityType as $entityType => $levels) {
            $definitions = $registry->coreFieldsFor($entityType);
            if ($definitions === []) {
                continue;
            }

            foreach ($levels as $field => $level) {
                $definition = $definitions[$field] ?? null;
                if (!$definition instanceof FieldDefinitionInterface) {
                    throw new \RuntimeException(sprintf(
                        'Classification targets unknown field %s.%s.',
                        $entityType,
                        $field,
                    ));
                }
                $declared = $definition instanceof FieldReadDefinitionInterface
                    ? $definition->getReadLevel()
                    : null;
                if ($declared !== null && $declared !== $level) {
                    throw new \RuntimeException(sprintf(
                        'Classification for %s.%s conflicts with its declared read level.',
                        $entityType,
                        $field,
                    ));
                }
                if ($declared === null) {
                    $definitions[$field] = new ClassifiedFieldDefinition($definition, $level);
                }
            }

            $registry->registerCoreFields($entityType, $definitions);
        }
    }
}
