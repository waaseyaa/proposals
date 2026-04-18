<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProposalDocument extends ContentEntityBase
{
    protected string $entityTypeId = 'proposal_document';

    /** @var array<string, string> */
    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'label',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldDefinitions = [
        'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
        'submission_id' => ['type' => 'integer', 'label' => 'Submission ID'],
        'document_type' => ['type' => 'string', 'label' => 'Document Type'],
        'format' => ['type' => 'string', 'label' => 'Format'],
        'version' => ['type' => 'string', 'label' => 'Version'],
        'storage_path' => ['type' => 'string', 'label' => 'Storage Path'],
        'source_hash' => ['type' => 'string', 'label' => 'Source Hash'],
        'metadata' => ['type' => 'json', 'label' => 'Metadata'],
        'generated_at' => ['type' => 'datetime', 'label' => 'Generated At'],
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }
}
