<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProposalSubmission extends ContentEntityBase
{
    protected string $entityTypeId = 'proposal_submission';

    /** @var array<string, string> */
    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldDefinitions = [
        'title' => ['type' => 'string', 'label' => 'Title', 'required' => true],
        'pipeline_id' => ['type' => 'integer', 'label' => 'Pipeline ID'],
        'owner_uid' => ['type' => 'integer', 'label' => 'Owner User ID'],
        'cohort_id' => ['type' => 'integer', 'label' => 'Cohort ID'],
        'status' => ['type' => 'string', 'label' => 'Status'],
        'current_step' => ['type' => 'string', 'label' => 'Current Step'],
        'applicant_name' => ['type' => 'string', 'label' => 'Applicant Name'],
        'business_name' => ['type' => 'string', 'label' => 'Business Name'],
        'canonical_data' => ['type' => 'json', 'label' => 'Canonical Data'],
        'completion_state' => ['type' => 'json', 'label' => 'Completion State'],
        'validation_state' => ['type' => 'json', 'label' => 'Validation State'],
        'confidence_state' => ['type' => 'json', 'label' => 'Confidence State'],
        'unresolved_items' => ['type' => 'json', 'label' => 'Unresolved Items'],
        'conversation_summary' => ['type' => 'text', 'label' => 'Conversation Summary'],
        'intake_transcript' => ['type' => 'json', 'label' => 'Intake Transcript'],
        'research_log' => ['type' => 'json', 'label' => 'Research Log'],
        'generated_document_index' => ['type' => 'json', 'label' => 'Generated Document Index'],
        'source_form_data' => ['type' => 'json', 'label' => 'Source Form Data'],
        'source_artifacts' => ['type' => 'json', 'label' => 'Source Artifacts'],
        'started_at' => ['type' => 'datetime', 'label' => 'Started At'],
        'submitted_at' => ['type' => 'datetime', 'label' => 'Submitted At'],
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }
}
