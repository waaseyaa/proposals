<?php

declare(strict_types=1);

namespace App\Support;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ProposalSchemaBootstrap
{
    private const ENTITY_TYPES = [
        'proposal_pipeline',
        'proposal_submission',
        'proposal_document',
        'proposal_review',
        'proposal_cohort',
    ];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DatabaseInterface $database,
    ) {}

    public function ensure(): void
    {
        foreach (self::ENTITY_TYPES as $entityTypeId) {
            $definition = $this->entityTypeManager->getDefinition($entityTypeId);
            $schema = new SqlSchemaHandler($definition, $this->database);
            $schema->ensureTable();
            $schema->addFieldColumns($this->buildColumnSpecs($definition->getFieldDefinitions()));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fieldDefinitions
     * @return array<string, array<string, mixed>>
     */
    private function buildColumnSpecs(array $fieldDefinitions): array
    {
        $columns = [];

        foreach ($fieldDefinitions as $fieldName => $definition) {
            $columns[$fieldName] = $this->columnSpecFor($definition['type'] ?? 'string');
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnSpecFor(string $type): array
    {
        return match ($type) {
            'integer' => [
                'type' => 'int',
                'not null' => false,
            ],
            'json', 'list', 'text' => [
                'type' => 'text',
                'not null' => false,
            ],
            default => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => false,
            ],
        };
    }
}
