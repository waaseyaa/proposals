<?php

declare(strict_types=1);

namespace App\Domain\Import;

use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class NorthOpsSeedImporter
{
    public function __construct(
        private readonly EntityStorageInterface $pipelineStorage,
        private readonly EntityStorageInterface $cohortStorage,
        private readonly EntityStorageInterface $submissionStorage,
        private readonly EntityStorageInterface $reviewStorage,
    ) {}

    /**
     * @return array{pipeline_id: int|string|null, cohort_id: int|string|null, submission_id: int|string|null}
     */
    public function import(string $sourceDirectory): array
    {
        $sourceDirectory = rtrim($sourceDirectory, '/');
        $formHtmlPath = $this->resolveExistingPath($sourceDirectory, [
            'ISET_Application_Package.html',
            'apps/iset-application/outputs/ISET_Application_Package.html',
        ]);
        $proposalHtmlPath = $this->resolveExistingPath($sourceDirectory, [
            'ISET_NorthOps_Submission.html',
            'apps/iset-application/outputs/ISET_NorthOps_Submission.html',
        ]);
        $packagePdfPath = $this->resolveExistingPath($sourceDirectory, [
            'ISET_NorthOps_Application_Package.pdf',
            'apps/iset-application/outputs/ISET_NorthOps_Application_Package.pdf',
        ]);

        if ($formHtmlPath === null) {
            throw new \RuntimeException(sprintf('NorthOps source form not found under "%s".', $sourceDirectory));
        }

        $formSnapshot = $this->extractFormSnapshot($formHtmlPath);
        $canonicalData = $this->buildCanonicalData($formSnapshot);

        $pipeline = $this->pipelineStorage->loadByKey('machine_name', 'iset_self_employment_sagamok_2025')
            ?? $this->pipelineStorage->create([
                'label' => 'Sagamok ISET Self-Employment Assistance',
                'machine_name' => 'iset_self_employment_sagamok_2025',
            ]);

        $pipeline->set('label', 'Sagamok ISET Self-Employment Assistance');
        $pipeline->set('machine_name', 'iset_self_employment_sagamok_2025');
        $pipeline->set('version', '2025');
        $pipeline->set('status', 'active');
        $pipeline->set('pipeline_type', 'iset_self_employment');
        $pipeline->set('appendix_order', ['A', 'B', 'F', 'G', 'H', 'M']);
        $pipeline->set('field_map', [
            'source' => 'NorthOps ISET HTML import',
            'strategy' => 'canonical-schema-first',
        ]);
        $pipeline->set('workflow_definition', [
            'states' => [
                'draft',
                'intake_in_progress',
                'ready_for_generation',
                'ready_for_review',
                'approved',
            ],
        ]);
        $pipeline->set('document_template_config', [
            'source_form' => $formHtmlPath,
            'source_submission' => is_file($proposalHtmlPath) ? $proposalHtmlPath : null,
            'source_package_pdf' => is_file($packagePdfPath) ? $packagePdfPath : null,
        ]);
        $this->pipelineStorage->save($pipeline);

        $cohort = $this->cohortStorage->loadByKey('label', 'OIATC Idea-to-Proposal Pilot')
            ?? $this->cohortStorage->create([
                'label' => 'OIATC Idea-to-Proposal Pilot',
            ]);

        $cohort->set('label', 'OIATC Idea-to-Proposal Pilot');
        $cohort->set('pipeline_id', $pipeline->id());
        $cohort->set('status', 'active');
        $cohort->set('capacity', 15);
        $cohort->set('starts_at', '2026-04-01T00:00:00+00:00');
        $cohort->set('ends_at', '2026-06-01T00:00:00+00:00');
        $this->cohortStorage->save($cohort);

        $submission = $this->submissionStorage->loadByKey('title', 'NorthOps — ISET Self-Employment Assistance')
            ?? $this->submissionStorage->create([
                'title' => 'NorthOps — ISET Self-Employment Assistance',
            ]);
        $existingStatus = (string) ($submission->get('status') ?? '');
        $existingCurrentStep = (string) ($submission->get('current_step') ?? '');
        $existingValidationState = $submission->get('validation_state');
        $existingConfidenceState = $submission->get('confidence_state');
        $existingUnresolvedItems = $submission->get('unresolved_items');
        $existingStartedAt = (string) ($submission->get('started_at') ?? '');
        $existingSubmittedAt = (string) ($submission->get('submitted_at') ?? '');

        $submission->set('title', 'NorthOps — ISET Self-Employment Assistance');
        $submission->set('pipeline_id', $pipeline->id());
        $submission->set('owner_uid', 1);
        $submission->set('cohort_id', $cohort->id());
        $submission->set('status', $existingStatus !== '' ? $existingStatus : 'draft');
        $submission->set('current_step', $existingCurrentStep !== '' ? $existingCurrentStep : 'structured_data');
        $submission->set('applicant_name', (string) ($formSnapshot['a_full_name'] ?? 'Russell Jones'));
        $submission->set('business_name', (string) ($formSnapshot['m_biz_name'] ?? 'NorthOps'));
        $submission->set('canonical_data', $canonicalData);
        $submission->set('completion_state', [
            'appendices' => [
                'A' => true,
                'B' => true,
                'F' => true,
                'G' => true,
                'H' => true,
                'M' => true,
            ],
            'imported_fields' => count($formSnapshot),
        ]);
        $validationState = is_array($existingValidationState) ? $existingValidationState : [];
        $validationState['import_source'] = basename($formHtmlPath);
        $validationState['status'] = 'seeded';
        $validationState['last_imported_at'] = gmdate(DATE_ATOM);
        $submission->set('validation_state', $validationState);

        $confidenceState = is_array($existingConfidenceState) ? $existingConfidenceState : [];
        $confidenceState['import_confidence'] = 'high';
        $confidenceState['source'] = 'NorthOps package import';
        $submission->set('confidence_state', $confidenceState);

        $submission->set('unresolved_items', is_array($existingUnresolvedItems) ? $existingUnresolvedItems : []);
        $submission->set('conversation_summary', 'Seeded from the latest NorthOps ISET package in ~/NorthOps for Waaseyaa proposal development.');
        $submission->set('intake_transcript', [
            [
                'role' => 'system',
                'content' => 'Submission initialized from the latest NorthOps ISET application package HTML.',
            ],
        ]);
        $submission->set('research_log', [
            [
                'type' => 'import',
                'message' => 'Imported existing ISET application package and supporting NorthOps artifacts.',
            ],
        ]);
        $submission->set('generated_document_index', [
            'html_form' => $formHtmlPath,
            'html_submission' => is_file($proposalHtmlPath) ? $proposalHtmlPath : null,
            'pdf_package' => is_file($packagePdfPath) ? $packagePdfPath : null,
        ]);
        $submission->set('source_form_data', $formSnapshot);
        $submission->set('source_artifacts', [
            'source_directory' => $sourceDirectory,
            'form_html' => $formHtmlPath,
            'submission_html' => is_file($proposalHtmlPath) ? $proposalHtmlPath : null,
            'package_pdf' => is_file($packagePdfPath) ? $packagePdfPath : null,
            'demo_html' => is_file($sourceDirectory . '/OIATC_Demo.html') ? $sourceDirectory . '/OIATC_Demo.html' : null,
        ]);
        $submission->set('started_at', $existingStartedAt !== '' ? $existingStartedAt : '2026-04-16T00:00:00+00:00');
        if ($existingSubmittedAt !== '') {
            $submission->set('submitted_at', $existingSubmittedAt);
        }
        $this->submissionStorage->save($submission);

        $this->seedDemoParticipantSubmissions($sourceDirectory, $pipeline->id(), $cohort->id());

        return [
            'pipeline_id' => $pipeline->id(),
            'cohort_id' => $cohort->id(),
            'submission_id' => $submission->id(),
        ];
    }

    /**
     * @param list<string> $candidates
     */
    private function resolveExistingPath(string $sourceDirectory, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = $sourceDirectory . '/' . ltrim($candidate, '/');
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function seedDemoParticipantSubmissions(string $sourceDirectory, int|string $pipelineId, int|string $cohortId): void
    {
        $samples = [
            [
                'title' => 'Jane Goodowl — Mino-Awiiyaas Catering (Demo)',
                'source' => $sourceDirectory . '/sources/OIATC/OIATC_Fictional_CEDO_Package_Jane_Goodowl_Catering.html',
                'status' => 'ready_for_review',
                'current_step' => 'review',
                'started_at' => '2026-04-10T00:00:00+00:00',
                'completion_appendices' => ['A' => true, 'B' => true, 'F' => true, 'G' => false, 'H' => true, 'M' => true],
                'unresolved_items' => [],
                'confidence_state' => [],
                'validation_state' => ['status' => 'demo_seeded'],
            ],
            [
                'title' => 'Jane Goodowl — Nookomis Textiles Studio (Demo)',
                'source' => $sourceDirectory . '/sources/OIATC/OIATC_Fictional_FullCEDO_Jane_Goodowl_NewBusiness_Textiles.html',
                'status' => 'intake_in_progress',
                'current_step' => 'intake',
                'started_at' => '2026-04-12T00:00:00+00:00',
                'completion_appendices' => ['A' => true, 'B' => false, 'F' => true, 'G' => false, 'H' => false, 'M' => true],
                'unresolved_items' => ['funding_request.support_rationale', 'business.market.marketing_plan'],
                'confidence_state' => [
                    'funding_request.support_rationale' => [
                        'confidence' => 0.61,
                        'note' => 'Needs clearer explanation of why the requested startup support changes launch viability.',
                        'resolved' => false,
                        'updated_at' => '2026-04-14T14:00:00+00:00',
                    ],
                    'business.market.marketing_plan' => [
                        'confidence' => 0.68,
                        'note' => 'Marketing plan is still too general. Add channels, launch tactics, and first customers.',
                        'resolved' => false,
                        'updated_at' => '2026-04-14T14:00:00+00:00',
                    ],
                ],
                'validation_state' => ['status' => 'demo_seeded'],
            ],
        ];

        foreach ($samples as $sample) {
            if (!is_file($sample['source'])) {
                continue;
            }

            $document = new \DOMDocument();
            libxml_use_internal_errors(true);
            $loaded = $document->loadHTML((string) file_get_contents($sample['source']));
            libxml_clear_errors();
            if ($loaded === false) {
                continue;
            }

            $xpath = new \DOMXPath($document);
            $headline = trim((string) $xpath->evaluate('string(//h1[1])'));
            $tagline = trim((string) $xpath->evaluate('string((//p[contains(@class,"tagline")] | //p[contains(@class,"fake-sub")] | //p[1])[1])'));
            $bodyLead = trim((string) $xpath->evaluate('string((//p[normalize-space()][2])[1])'));

            [$applicantName, $businessName] = $this->splitHeadline($headline);
            $canonical = [
                'applicant' => [
                    'identity' => [
                        'full_name' => $applicantName,
                    ],
                    'contact' => [
                        'email' => '',
                        'telephone' => '',
                    ],
                ],
                'business' => [
                    'identity' => [
                        'business_name' => $businessName,
                        'description' => $tagline,
                    ],
                    'market' => [
                        'marketing_plan' => $bodyLead,
                    ],
                ],
                'funding_request' => [
                    'support_rationale' => $bodyLead,
                ],
            ];

            $submission = $this->submissionStorage->loadByKey('title', (string) $sample['title'])
                ?? $this->submissionStorage->create([
                    'title' => (string) $sample['title'],
                ]);

            $existingStatus = (string) ($submission->get('status') ?? '');
            $existingCurrentStep = (string) ($submission->get('current_step') ?? '');
            $existingStartedAt = (string) ($submission->get('started_at') ?? '');

            $submission->set('title', (string) $sample['title']);
            $submission->set('pipeline_id', $pipelineId);
            $submission->set('owner_uid', 1);
            $submission->set('cohort_id', $cohortId);
            $submission->set('status', $existingStatus !== '' ? $existingStatus : (string) $sample['status']);
            $submission->set('current_step', $existingCurrentStep !== '' ? $existingCurrentStep : (string) $sample['current_step']);
            $submission->set('applicant_name', $applicantName);
            $submission->set('business_name', $businessName);
            $submission->set('canonical_data', $canonical);
            $submission->set('completion_state', [
                'appendices' => $sample['completion_appendices'],
                'imported_fields' => 3,
            ]);
            $validationState = array_merge(
                is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
                is_array($sample['validation_state']) ? $sample['validation_state'] : [],
                [
                    'import_source' => basename((string) $sample['source']),
                    'last_imported_at' => gmdate(DATE_ATOM),
                ],
            );
            $validationState['reviewed_appendices'] = $this->demoReviewedAppendices((string) $sample['title']);
            $submission->set('validation_state', $validationState);
            $submission->set('confidence_state', $sample['confidence_state']);
            $submission->set('unresolved_items', $sample['unresolved_items']);
            $submission->set('conversation_summary', 'Seeded from OIATC fictional sample package for cohort board development.');
            $submission->set('intake_transcript', [
                [
                    'role' => 'system',
                    'content' => 'Submission initialized from OIATC fictional sample package HTML.',
                ],
            ]);
            $submission->set('research_log', [
                [
                    'type' => 'import',
                    'message' => 'Imported OIATC fictional sample package into cohort board.',
                ],
            ]);
            $submission->set('generated_document_index', [
                'source_html' => (string) $sample['source'],
            ]);
            $submission->set('source_form_data', [
                'headline' => $headline,
                'tagline' => $tagline,
                'lead' => $bodyLead,
            ]);
            $submission->set('source_artifacts', [
                'source_directory' => $sourceDirectory,
                'sample_html' => (string) $sample['source'],
            ]);
            $submission->set('started_at', $existingStartedAt !== '' ? $existingStartedAt : (string) $sample['started_at']);
            $this->submissionStorage->save($submission);
            $this->seedDemoReviewActions($submission, (string) $sample['title']);
        }
    }

    /**
     * @return array<string, array{reviewed:bool,reviewed_at:string,reviewer_uid:int}>
     */
    private function demoReviewedAppendices(string $title): array
    {
        $map = [
            'A' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
            'B' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
            'F' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
            'G' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
            'H' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
            'M' => ['reviewed' => false, 'reviewed_at' => '', 'reviewer_uid' => 0],
        ];

        if (str_contains($title, 'Mino-Awiiyaas Catering')) {
            foreach (['A', 'B', 'F', 'M'] as $appendix) {
                $map[$appendix] = [
                    'reviewed' => true,
                    'reviewed_at' => '2026-04-15T15:00:00+00:00',
                    'reviewer_uid' => 1,
                ];
            }
        }

        if (str_contains($title, 'Nookomis Textiles Studio')) {
            $map['A'] = [
                'reviewed' => true,
                'reviewed_at' => '2026-04-15T15:30:00+00:00',
                'reviewer_uid' => 1,
            ];
        }

        return $map;
    }

    private function seedDemoReviewActions(object $submission, string $title): void
    {
        $existing = array_filter(
            $this->reviewStorage->loadMultiple($this->reviewStorage->getQuery()->execute()),
            static fn (object $review): bool => (int) ($review->get('submission_id') ?? 0) === (int) $submission->id(),
        );

        if ($existing !== []) {
            return;
        }

        $actions = [];
        if (str_contains($title, 'Mino-Awiiyaas Catering')) {
            $actions[] = [
                'title' => 'Comment on Jane Goodowl — Mino-Awiiyaas Catering (Demo)',
                'section_key' => 'appendix_g',
                'field_path' => 'career_plan.financial_impact',
                'comment' => 'Appendix G still needs a final cost check before full approval.',
                'action_type' => 'comment',
                'created_at' => '2026-04-15T15:10:00+00:00',
            ];
        }

        if (str_contains($title, 'Nookomis Textiles Studio')) {
            $actions[] = [
                'title' => 'Comment on Jane Goodowl — Nookomis Textiles Studio (Demo)',
                'section_key' => 'intake',
                'field_path' => 'funding_request.support_rationale',
                'comment' => 'Support rationale is still too general. Intake needs a clearer explanation of what grant funding unlocks.',
                'action_type' => 'comment',
                'created_at' => '2026-04-15T15:40:00+00:00',
            ];
        }

        foreach ($actions as $action) {
            $review = $this->reviewStorage->create([
                'title' => $action['title'],
            ]);
            $review->set('title', $action['title']);
            $review->set('submission_id', $submission->id());
            $review->set('reviewer_uid', 1);
            $review->set('section_key', $action['section_key']);
            $review->set('field_path', $action['field_path']);
            $review->set('comment', $action['comment']);
            $review->set('action_type', $action['action_type']);
            $review->set('created_at', $action['created_at']);
            $this->reviewStorage->save($review);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitHeadline(string $headline): array
    {
        $parts = preg_split('/\s+[\x{2014}\-]\s+/u', $headline, 2) ?: [];
        $applicantName = trim((string) ($parts[0] ?? 'Demo Applicant'));
        $businessName = trim((string) ($parts[1] ?? 'Demo Business'));

        return [$applicantName, $businessName];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFormSnapshot(string $htmlPath): array
    {
        $html = file_get_contents($htmlPath);

        if ($html === false) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $htmlPath));
        }

        libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $snapshot = [];

        foreach ($xpath->query('//input[@id or @name]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $type = strtolower($node->getAttribute('type') ?: 'text');
            $id = trim($node->getAttribute('id'));
            $name = trim($node->getAttribute('name'));
            $key = $id !== '' ? $id : $name;

            if ($key === '') {
                continue;
            }

            if ($type === 'radio') {
                if ($node->hasAttribute('checked')) {
                    $snapshot['radio_' . $name] = $node->getAttribute('value');
                }
                continue;
            }

            if ($type === 'checkbox') {
                $checkboxKey = $id !== '' ? $id : sprintf('%s_%s', $name, $node->getAttribute('value'));
                $snapshot['check_' . $checkboxKey] = $node->hasAttribute('checked');
                continue;
            }

            $snapshot[$key] = $node->getAttribute('value');
        }

        foreach ($xpath->query('//textarea[@id or @name]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $key = trim($node->getAttribute('id')) ?: trim($node->getAttribute('name'));
            if ($key === '') {
                continue;
            }

            $snapshot[$key] = trim($node->textContent);
        }

        foreach ($xpath->query('//select[@id or @name]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $key = trim($node->getAttribute('id')) ?: trim($node->getAttribute('name'));
            if ($key === '') {
                continue;
            }

            $selected = null;
            foreach ($xpath->query('.//option[@selected]', $node) as $option) {
                if ($option instanceof \DOMElement) {
                    $selected = $option->getAttribute('value') !== ''
                        ? $option->getAttribute('value')
                        : trim($option->textContent);
                    break;
                }
            }

            $snapshot[$key] = $selected;
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function buildCanonicalData(array $form): array
    {
        $fullName = $this->stringValue($form, ['a_full_name', 'f_name', 'h_student_name', 'm_legal_name']);
        $phone = $this->stringValue($form, ['a_phone', 'a_tel', 'f_tel']);
        $email = $this->stringValue($form, ['a_email', 'f_email']);
        $businessName = $this->stringValue($form, ['m_biz_name', 'm_business_name']);
        $businessDescription = $this->stringValue($form, ['m_objectives', 'm_biz_desc', 'm_description', 'm_business_description']);

        return [
            'applicant' => [
                'identity' => [
                    'full_name' => $fullName,
                    'birth_date' => $this->stringValue($form, ['a_birthdate', 'a_birth_date', 'a_dob']),
                    'marital_status' => $this->stringValue($form, ['radio_a_marital', 'radio_a_marital_status', 'a_marital_status']),
                    'dependents_count' => (int) ($this->stringValue($form, ['a_dependents']) ?: '0'),
                    'dependents_ages' => $this->splitList($this->stringValue($form, ['a_dep_ages'])),
                    'gender' => $this->stringValue($form, ['radio_a_gender']),
                    'disability_status' => $this->normalizeYesNo($this->stringValue($form, ['radio_a_disability'])),
                    'aboriginal_group' => $this->stringValue($form, ['radio_a_aboriginal', 'a_aboriginal_group', 'a_indigenous_identity']),
                    'band_number' => $this->stringValue($form, ['a_band_num', 'a_band_number']),
                    'band_community' => $this->stringValue($form, ['a_band_community', 'a_band_name', 'a_community']),
                    'preferred_language' => $this->stringValue($form, ['radio_a_lang', 'a_language_preferred']),
                    'second_language' => $this->stringValue($form, ['a_second_lang']),
                ],
                'contact' => [
                    'email' => $email,
                    'telephone' => $phone,
                    'address' => [
                        'po_box' => $this->stringValue($form, ['a_pobox']),
                        'street' => $this->stringValue($form, ['a_street', 'a_address', 'a_street_address']),
                        'town' => $this->stringValue($form, ['a_city', 'a_town']),
                        'province' => $this->stringValue($form, ['a_prov', 'a_province']),
                        'postal_code' => $this->stringValue($form, ['a_postal', 'a_postal_code']),
                        'residence_type' => $this->stringValue($form, ['radio_a_residence']),
                    ],
                    'emergency_contact' => [
                        'name' => $this->stringValue($form, ['a_emerg_name', 'a_emergency_contact_name']),
                        'telephone' => $this->stringValue($form, ['a_emerg_tel', 'a_emergency_contact_phone']),
                        'relationship' => $this->stringValue($form, ['a_emerg_rel', 'a_emergency_contact_relationship']),
                    ],
                ],
                'education' => [
                    'elementary_completed' => (bool) ($form['check_a_elem_complete'] ?? false),
                    'high_school_completed' => (bool) ($form['check_a_hs_complete'] ?? false),
                    'highest_grade' => $this->stringValue($form, ['radio_a_grade']),
                    'year_completed' => $this->stringValue($form, ['a_year_completed']),
                    'post_secondary' => [
                        [
                            'institution' => $this->stringValue($form, ['a_ps1_inst']),
                            'province' => $this->stringValue($form, ['a_ps1_prov']),
                            'year' => $this->stringValue($form, ['a_ps1_year']),
                        ],
                        [
                            'institution' => $this->stringValue($form, ['a_ps2_inst']),
                            'province' => $this->stringValue($form, ['a_ps2_prov']),
                            'year' => $this->stringValue($form, ['a_ps2_year']),
                        ],
                    ],
                    'licenses_certificates' => $this->splitList($this->stringValue($form, ['a_licenses'])),
                ],
                'employment' => [
                    'employment_status' => $this->stringValue($form, ['radio_a_employment', 'radio_a_employment_status', 'a_employment_status']),
                    'income_sources' => $this->collectCheckedLabels($form, 'check_a_income_'),
                    'last_employer' => $this->stringValue($form, ['a_employer', 'a_last_employer']),
                    'job_title' => $this->stringValue($form, ['a_job_title']),
                    'employed_from' => $this->stringValue($form, ['a_employed_from']),
                    'employed_to' => $this->stringValue($form, ['a_employed_to']),
                    'leaving_reasons' => $this->collectCheckedLabels($form, 'check_a_leaving_'),
                    'barriers' => $this->collectCheckedLabels($form, 'check_a_barrier_'),
                    'barriers_other' => $this->stringValue($form, ['a_barrier_other']),
                    'willing_to_relocate' => $this->boolValue($form, ['check_a_relocate_yes', 'check_a_willing_to_relocate']),
                    'has_transportation' => $this->boolValue($form, ['check_a_transportation_yes', 'check_a_has_transportation']),
                ],
            ],
            'funding_request' => [
                'activity_type' => $this->stringValue($form, ['radio_f_activity_type', 'radio_f_activity']),
                'career_goals' => $this->stringValue($form, ['f_goals', 'f_career_goal', 'f_career_goals']),
                'support_rationale' => $this->stringValue($form, ['f_support', 'f_support_rationale', 'f_training_need']),
                'post_program_intent' => $this->stringValue($form, ['f_post_program_intent']),
                'timeframe' => [
                    'from' => $this->stringValue($form, ['f_from', 'f_start_date']),
                    'to' => $this->stringValue($form, ['f_to', 'f_end_date']),
                ],
                'three_year_plan' => $this->stringValue($form, ['f_three_year']),
            ],
            'career_plan' => [
                'career_goal' => $this->stringValue($form, ['g_career_goal']),
                'goal_reason' => $this->stringValue($form, ['g_reason', 'g_goal_reason']),
                'job_searches' => [
                    [
                        'source' => $this->stringValue($form, ['radio_g_js1_source']),
                        'employers' => $this->stringValue($form, ['g_js1_employers']),
                        'employer' => $this->stringValue($form, ['g_js1_employer']),
                        'position' => $this->stringValue($form, ['g_js1_position']),
                        'responsibilities' => $this->stringValue($form, ['g_js1_resp']),
                        'location' => $this->stringValue($form, ['g_js1_location']),
                        'wage_start' => $this->stringValue($form, ['g_js1_wage_start']),
                        'wage_top' => $this->stringValue($form, ['g_js1_wage_top']),
                    ],
                    [
                        'source' => $this->stringValue($form, ['radio_g_js2_source']),
                        'employers' => $this->stringValue($form, ['g_js2_employers']),
                        'employer' => $this->stringValue($form, ['g_js2_employer']),
                        'position' => $this->stringValue($form, ['g_js2_position']),
                        'responsibilities' => $this->stringValue($form, ['g_js2_resp']),
                        'location' => $this->stringValue($form, ['g_js2_location']),
                        'wage_start' => $this->stringValue($form, ['g_js2_wage_start']),
                        'wage_top' => $this->stringValue($form, ['g_js2_wage_top']),
                    ],
                    [
                        'source' => $this->stringValue($form, ['radio_g_js3_source']),
                        'employers' => $this->stringValue($form, ['g_js3_employers']),
                        'employer' => $this->stringValue($form, ['g_js3_employer']),
                        'position' => $this->stringValue($form, ['g_js3_position']),
                        'responsibilities' => $this->stringValue($form, ['g_js3_resp']),
                        'location' => $this->stringValue($form, ['g_js3_location']),
                        'wage_start' => $this->stringValue($form, ['g_js3_wage_start']),
                        'wage_top' => $this->stringValue($form, ['g_js3_wage_top']),
                    ],
                ],
                'institutions' => [
                    'preferred' => [
                        'institution_name' => $this->stringValue($form, ['g_pref_name']),
                        'course_name' => $this->stringValue($form, ['g_pref_course']),
                        'delivery' => $this->stringValue($form, ['g_pref_delivery']),
                        'length' => $this->stringValue($form, ['g_pref_length']),
                        'address' => $this->stringValue($form, ['g_pref_addr']),
                    ],
                    'alternate' => [
                        'institution_name' => $this->stringValue($form, ['g_alt_name']),
                        'course_name' => $this->stringValue($form, ['g_alt_course']),
                        'delivery' => $this->stringValue($form, ['g_alt_delivery']),
                        'length' => $this->stringValue($form, ['g_alt_length']),
                        'address' => $this->stringValue($form, ['g_alt_addr']),
                    ],
                ],
                'three_year_plan' => $this->stringValue($form, ['f_three_year', 'g_three_year_plan']),
            ],
            'business' => [
                'identity' => [
                    'legal_name' => $this->stringValue($form, ['m_legal_name']) ?: $fullName,
                    'business_name' => $businessName,
                    'classification' => implode(', ', $this->collectCheckedLabels($form, 'check_m_class_')),
                    'description' => $businessDescription,
                ],
                'operations' => [
                    'location' => trim(implode(', ', array_filter([
                        $this->stringValue($form, ['m_biz_addr']),
                        $this->stringValue($form, ['m_biz_city']),
                        $this->stringValue($form, ['m_biz_prov']),
                        $this->stringValue($form, ['m_biz_postal']),
                    ]))),
                    'location_type' => $this->stringValue($form, ['radio_m_location']),
                    'hours_of_operation' => $this->stringValue($form, ['m_hours_op', 'm_hours']),
                    'weekly_hours' => $this->stringValue($form, ['m_weekly_hours']),
                    'business_status' => $this->stringValue($form, ['radio_m_status', 'm_business_status']),
                    'owner_involvement' => $this->stringValue($form, ['radio_m_involvement']),
                    'launch_timeline' => $this->stringValue($form, ['m_anticipated_date', 'm_launch_timeline']),
                    'regulatory_requirements' => $this->splitList($this->stringValue($form, ['m_regulations'])),
                ],
                'market' => [
                    'customers' => ['Businesses', 'First Nations', 'Organizations'],
                    'service_area' => 'Ontario and remote clients nationally',
                    'market_gap' => 'Senior-level Indigenous-owned software engineering and AI delivery anchored in sovereign infrastructure.',
                ],
                'ownership' => [
                    'ownership_model' => $this->stringValue($form, ['radio_m_ownership']),
                    'owners' => [[
                        'name' => $fullName,
                        'role' => 'Founder',
                    ]],
                ],
                'financials' => [
                    'startup_costs' => $this->collectMatching($form, '/^(m|f)_(startup|cost|expense)/'),
                    'funding_sources' => $this->collectMatching($form, '/^m_fund_/'),
                    'cash_flow_notes' => $this->stringValue($form, ['m_cash_flow_notes', 'm_financial_notes']),
                ],
            ],
            'consents' => [
                'appendix_b' => [
                    'signed_by' => $this->stringValue($form, ['b_print_name', 'b_signed_by']),
                    'signed_at' => $this->stringValue($form, ['b_date']),
                    'witness_name' => $this->stringValue($form, ['b_witness']),
                ],
                'appendix_h' => [
                    'institution' => $this->stringValue($form, ['h_institution']),
                    'student_name' => $this->stringValue($form, ['h_student_name']),
                    'signed_at' => $this->stringValue($form, ['h_date']),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string> $keys
     */
    private function stringValue(array $form, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $form)) {
                continue;
            }

            $value = trim((string) $form[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string> $keys
     */
    private function boolValue(array $form, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $form)) {
                return (bool) $form[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $form
     * @return list<string>
     */
    private function collectCheckedLabels(array $form, string $prefix): array
    {
        $matches = [];

        foreach ($form as $key => $value) {
            if (!str_starts_with((string) $key, $prefix) || $value !== true) {
                continue;
            }

            $matches[] = str_replace('_', ' ', substr((string) $key, strlen($prefix)));
        }

        return $matches;
    }

    private function normalizeYesNo(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'yes', 'y' => true,
            'no', 'n' => false,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $parts = preg_split('/\s*[,;]\s*/', $value);
        if ($parts === false) {
            return [trim($value)];
        }

        return array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts)));
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function collectMatching(array $form, string $pattern): array
    {
        $matches = [];

        foreach ($form as $key => $value) {
            if (preg_match($pattern, (string) $key) === 1) {
                $matches[$key] = $value;
            }
        }

        return $matches;
    }
}
