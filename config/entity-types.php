<?php

declare(strict_types=1);

use App\Entity\ProposalCohort;
use App\Entity\ProposalDocument;
use App\Entity\ProposalPipeline;
use App\Entity\ProposalReview;
use App\Entity\ProposalSubmission;
use Waaseyaa\Entity\EntityType;

$contentKeys = static fn(string $labelField): array => [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => $labelField,
];

return [
    new EntityType(
        id: 'proposal_pipeline',
        label: 'Proposal Pipeline',
        class: ProposalPipeline::class,
        keys: $contentKeys('label'),
        fieldDefinitions: [
            'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
            'machine_name' => ['type' => 'string', 'label' => 'Machine Name', 'required' => true],
            'version' => ['type' => 'string', 'label' => 'Version'],
            'status' => ['type' => 'string', 'label' => 'Status'],
            'pipeline_type' => ['type' => 'string', 'label' => 'Pipeline Type'],
            'appendix_order' => ['type' => 'json', 'label' => 'Appendix Order'],
            'field_map' => ['type' => 'json', 'label' => 'Field Map'],
            'workflow_definition' => ['type' => 'json', 'label' => 'Workflow Definition'],
            'document_template_config' => ['type' => 'json', 'label' => 'Document Template Config'],
        ],
        description: 'Reusable funding or proposal pipeline definition.',
    ),
    new EntityType(
        id: 'proposal_submission',
        label: 'Proposal Submission',
        class: ProposalSubmission::class,
        keys: $contentKeys('title'),
        fieldDefinitions: [
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
        ],
        description: 'A participant submission moving through a proposal pipeline.',
    ),
    new EntityType(
        id: 'proposal_document',
        label: 'Proposal Document',
        class: ProposalDocument::class,
        keys: $contentKeys('label'),
        fieldDefinitions: [
            'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
            'submission_id' => ['type' => 'integer', 'label' => 'Submission ID'],
            'document_type' => ['type' => 'string', 'label' => 'Document Type'],
            'format' => ['type' => 'string', 'label' => 'Format'],
            'version' => ['type' => 'string', 'label' => 'Version'],
            'storage_path' => ['type' => 'string', 'label' => 'Storage Path'],
            'source_hash' => ['type' => 'string', 'label' => 'Source Hash'],
            'metadata' => ['type' => 'json', 'label' => 'Metadata'],
            'generated_at' => ['type' => 'datetime', 'label' => 'Generated At'],
        ],
        description: 'Generated output artifact for a proposal submission.',
    ),
    new EntityType(
        id: 'proposal_review',
        label: 'Proposal Review',
        class: ProposalReview::class,
        keys: $contentKeys('title'),
        fieldDefinitions: [
            'title' => ['type' => 'string', 'label' => 'Title', 'required' => true],
            'submission_id' => ['type' => 'integer', 'label' => 'Submission ID'],
            'reviewer_uid' => ['type' => 'integer', 'label' => 'Reviewer User ID'],
            'section_key' => ['type' => 'string', 'label' => 'Section Key'],
            'field_path' => ['type' => 'string', 'label' => 'Field Path'],
            'comment' => ['type' => 'text', 'label' => 'Comment'],
            'action_type' => ['type' => 'string', 'label' => 'Action Type'],
            'created_at' => ['type' => 'datetime', 'label' => 'Created At'],
        ],
        description: 'Staff review comment or workflow action on a submission.',
    ),
    new EntityType(
        id: 'proposal_cohort',
        label: 'Proposal Cohort',
        class: ProposalCohort::class,
        keys: $contentKeys('label'),
        fieldDefinitions: [
            'label' => ['type' => 'string', 'label' => 'Label', 'required' => true],
            'pipeline_id' => ['type' => 'integer', 'label' => 'Pipeline ID'],
            'status' => ['type' => 'string', 'label' => 'Status'],
            'capacity' => ['type' => 'integer', 'label' => 'Capacity'],
            'starts_at' => ['type' => 'datetime', 'label' => 'Starts At'],
            'ends_at' => ['type' => 'datetime', 'label' => 'Ends At'],
        ],
        description: 'Cohort wrapper for a group of participants sharing a proposal pipeline.',
    ),
];
