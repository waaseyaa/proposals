<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProposalPipeline extends ContentEntityBase
{
    protected string $entityTypeId = 'proposal_pipeline';

    /** @var array<string, string> */
    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'label',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldDefinitions = [
        'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
        'machine_name' => ['type' => 'string', 'label' => 'Machine Name', 'required' => true],
        'version' => ['type' => 'string', 'label' => 'Version'],
        'status' => ['type' => 'string', 'label' => 'Status'],
        'pipeline_type' => ['type' => 'string', 'label' => 'Pipeline Type'],
        'appendix_order' => ['type' => 'json', 'label' => 'Appendix Order'],
        'field_map' => ['type' => 'json', 'label' => 'Field Map'],
        'workflow_definition' => ['type' => 'json', 'label' => 'Workflow Definition'],
        'document_template_config' => ['type' => 'json', 'label' => 'Document Template Config'],
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }
}
