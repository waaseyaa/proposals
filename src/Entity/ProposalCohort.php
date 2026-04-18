<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProposalCohort extends ContentEntityBase
{
    protected string $entityTypeId = 'proposal_cohort';

    /** @var array<string, string> */
    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'label',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldDefinitions = [
        'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
        'pipeline_id' => ['type' => 'integer', 'label' => 'Pipeline ID'],
        'status' => ['type' => 'string', 'label' => 'Status'],
        'capacity' => ['type' => 'integer', 'label' => 'Capacity'],
        'starts_at' => ['type' => 'datetime', 'label' => 'Starts At'],
        'ends_at' => ['type' => 'datetime', 'label' => 'Ends At'],
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }
}
