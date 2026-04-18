<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProposalReview extends ContentEntityBase
{
    protected string $entityTypeId = 'proposal_review';

    /** @var array<string, string> */
    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldDefinitions = [
        'title' => ['type' => 'string', 'label' => 'Title', 'required' => true],
        'submission_id' => ['type' => 'integer', 'label' => 'Submission ID'],
        'reviewer_uid' => ['type' => 'integer', 'label' => 'Reviewer User ID'],
        'section_key' => ['type' => 'string', 'label' => 'Section Key'],
        'field_path' => ['type' => 'string', 'label' => 'Field Path'],
        'comment' => ['type' => 'text', 'label' => 'Comment'],
        'action_type' => ['type' => 'string', 'label' => 'Action Type'],
        'created_at' => ['type' => 'datetime', 'label' => 'Created At'],
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }
}
