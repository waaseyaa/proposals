<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class DocumentPreviewService
{
    private const FOOTER = 'Education Department, 717 Sagamok Road, Sagamok ON P0P 2L0, Telephone: (705) 865-2421';

    public function __construct(
        private readonly EntityStorageInterface $documentStorage,
        private readonly string $projectRoot,
    ) {}

    /**
     * @return array<int, array{document_type: string, label: string, summary: string, html: string}>
     */
    public function buildAndPersist(EntityInterface $submission): array
    {
        [$canonical, $source] = $this->extractData($submission);

        $documents = [
            [
                'document_type' => 'appendix_a_preview',
                'label' => 'Appendix A',
                'summary' => 'Client Data Form',
                'html' => $this->renderAppendixA($canonical, $source),
            ],
            [
                'document_type' => 'appendix_b_preview',
                'label' => 'Appendix B',
                'summary' => 'Consent to collect, use and disclose personal information',
                'html' => $this->renderAppendixB($canonical, $source),
            ],
            [
                'document_type' => 'appendix_f_preview',
                'label' => 'Appendix F',
                'summary' => 'Request for Funding',
                'html' => $this->renderAppendixF($canonical, $source),
            ],
            [
                'document_type' => 'appendix_g_preview',
                'label' => 'Appendix G',
                'summary' => 'Career Action Plan',
                'html' => $this->renderAppendixG($canonical, $source),
            ],
            [
                'document_type' => 'appendix_h_preview',
                'label' => 'Appendix H',
                'summary' => 'Authorization of Release',
                'html' => $this->renderAppendixH($canonical, $source),
            ],
            [
                'document_type' => 'appendix_m_preview',
                'label' => 'Appendix M',
                'summary' => 'Business Proposal Summary',
                'html' => $this->renderAppendixM($canonical, $source),
            ],
        ];

        $this->persistDocuments($submission, $documents);

        return $documents;
    }

    /**
     * @return array{document_type: string, label: string, summary: string, html: string}
     */
    public function buildPackageAndPersist(EntityInterface $submission): array
    {
        $documents = $this->buildAndPersist($submission);
        $html = $this->renderPackageCover($submission) . implode('', array_column($documents, 'html'));

        $package = [
            'document_type' => 'merged_package_preview',
            'label' => 'Merged ISET Package',
            'summary' => 'Cover sheet and appendices A, B, F, G, H, and M in printable order.',
            'html' => $html,
        ];

        $this->persistDocuments($submission, [$package]);

        return $package;
    }

    public function pageStyles(): string
    {
        return <<<'CSS'
    :root {
      --bg: #f6efe4;
      --panel: #fffdfa;
      --ink: #1d1915;
      --muted: #6e665d;
      --line: #d9ccb9;
      --primary: #315845;
      --accent: #b95a2f;
      --card: #f5ede1;
      --success: #21663a;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      font-family: Georgia, "Times New Roman", serif;
      background:
        radial-gradient(circle at top right, rgba(185, 90, 47, 0.10), transparent 24%),
        linear-gradient(180deg, #faf5ee 0%, var(--bg) 100%);
    }
    main {
      max-width: 1180px;
      margin: 0 auto;
      padding: 32px 24px 64px;
    }
    a { color: var(--primary); }
    .hero {
      margin-bottom: 24px;
      padding: 24px;
      border-radius: 18px;
      border: 1px solid var(--line);
      background: rgba(255, 253, 250, 0.92);
      box-shadow: 0 10px 30px rgba(76, 62, 44, 0.06);
    }
    .eyebrow {
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 0.72rem;
      color: var(--accent);
      font-weight: 700;
    }
    h1 {
      margin: 8px 0 10px;
      font-size: clamp(2rem, 4vw, 3.4rem);
      letter-spacing: -0.04em;
    }
    p { line-height: 1.8; color: var(--muted); }
    .nav {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 16px;
    }
    .nav a {
      padding: 8px 14px;
      border: 1px solid var(--line);
      border-radius: 999px;
      text-decoration: none;
      background: rgba(255,255,255,0.7);
    }
    .stack { display: grid; gap: 20px; }
    .panel {
      display: block;
      border-radius: 18px;
      overflow: hidden;
      border: 1px solid var(--line);
      background: var(--panel);
      box-shadow: 0 10px 30px rgba(76, 62, 44, 0.06);
    }
    .panel-header {
      padding: 22px 24px 14px;
      border-bottom: 1px solid var(--line);
      background: linear-gradient(180deg, rgba(49, 88, 69, 0.05), rgba(255,255,255,0));
    }
    .panel-header h2 {
      margin: 0;
      font-size: 1.8rem;
    }
    .panel-header p {
      margin: 6px 0 0;
      font-size: 1rem;
    }
    .accent-line {
      height: 4px;
      width: 92px;
      background: linear-gradient(90deg, var(--primary), var(--accent));
      border-radius: 999px;
      margin-top: 14px;
    }
    .form-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 18px;
      padding: 12px 24px;
      font-size: 0.86rem;
      color: var(--muted);
      background: var(--card);
      border-bottom: 1px solid var(--line);
    }
    .form-section {
      margin: 18px 24px;
      padding: 18px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: #fffefd;
    }
    .form-section h3 {
      margin: 0 0 14px;
      font-size: 1.1rem;
      color: var(--primary);
    }
    .form-section h4 {
      margin: 18px 0 10px;
      font-size: 1rem;
      color: var(--ink);
    }
    .field-group {
      display: grid;
      gap: 14px;
      margin-bottom: 14px;
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .field-group.cols-1 { grid-template-columns: 1fr; }
    .field-group.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .field-group.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .field.full { grid-column: 1 / -1; }
    .field label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      color: var(--muted);
      text-transform: uppercase;
    }
    .value-box, .text-box {
      min-height: 40px;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: #fff;
      line-height: 1.6;
      white-space: pre-wrap;
    }
    .text-box { min-height: 94px; }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .chip {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(49, 88, 69, 0.09);
      border: 1px solid rgba(49, 88, 69, 0.12);
      color: var(--primary);
      font-size: 0.85rem;
    }
    .form-table {
      width: 100%;
      border-collapse: collapse;
    }
    .form-table th,
    .form-table td {
      border: 1px solid var(--line);
      padding: 10px 12px;
      text-align: left;
      vertical-align: top;
    }
    .form-table th {
      background: var(--card);
      font-size: 0.84rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
    }
    .info-box {
      margin-top: 16px;
      padding: 14px 16px;
      border: 1px solid rgba(49, 88, 69, 0.16);
      border-radius: 12px;
      background: rgba(49, 88, 69, 0.05);
      line-height: 1.7;
    }
    .consent-text {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      line-height: 1.75;
    }
    .print-footer {
      display: block;
      text-align: center;
      font-size: 0.82rem;
      color: var(--muted);
      border-top: 1px solid var(--line);
      padding: 10px 24px 16px;
      margin-top: 20px;
    }
    .package-cover {
      padding: 40px 32px;
      min-height: 420px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 18px;
      text-align: left;
    }
    .package-cover h2 {
      margin: 0;
      font-size: 2.5rem;
      letter-spacing: -0.05em;
    }
    .cover-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-top: 8px;
    }
    .cover-grid .card {
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 14px;
      background: rgba(255,255,255,0.75);
    }
    .cover-grid strong {
      display: block;
      margin-bottom: 6px;
      color: var(--primary);
    }
    @media (max-width: 860px) {
      .field-group,
      .field-group.cols-3,
      .field-group.cols-4,
      .cover-grid {
        grid-template-columns: 1fr;
      }
    }
    @media print {
      body { background: #fff; font-size: 10pt; }
      .hero, .nav { display: none !important; }
      main { max-width: 100%; padding: 0; margin: 0; }
      .stack { gap: 0; }
      .panel { box-shadow: none; border-radius: 0; border: none; page-break-before: always; }
      .panel:first-of-type { page-break-before: auto; }
      .form-section { page-break-inside: avoid; }
      .print-footer { margin-top: 24px; }
    }
CSS;
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function ensureHtmlArtifact(EntityInterface $submission, string $documentType, string $html): array
    {
        $directory = $this->projectRoot . '/storage/proposals/generated/' . (string) $submission->id();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create artifact directory "%s".', $directory));
        }

        $filename = $this->filenameFor($documentType);
        $path = $directory . '/' . $filename;

        $wrappedHtml = $this->wrapArtifactHtml($documentType, $html);
        if (file_put_contents($path, $wrappedHtml) === false) {
            throw new \RuntimeException(sprintf('Unable to write HTML artifact "%s".', $path));
        }

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractData(EntityInterface $submission): array
    {
        $canonical = $submission->get('canonical_data');
        $source = $submission->get('source_form_data');

        return [
            is_array($canonical) ? $canonical : [],
            is_array($source) ? $source : [],
        ];
    }

    /**
     * @param array<int, array{document_type: string, label: string, summary: string, html: string}> $documents
     */
    private function persistDocuments(EntityInterface $submission, array $documents): void
    {
        $existing = [];
        foreach ($this->documentStorage->loadMultiple($this->documentStorage->getQuery()->execute()) as $document) {
            if ((int) ($document->get('submission_id') ?? 0) !== (int) $submission->id()) {
                continue;
            }
            $existing[(string) $document->get('document_type')] = $document;
        }

        $generatedAt = gmdate(DATE_ATOM);
        foreach ($documents as $documentData) {
            $document = $existing[$documentData['document_type']] ?? $this->documentStorage->create([
                'label' => sprintf('%s #%s', $documentData['label'], (string) $submission->id()),
            ]);
            $artifact = $this->ensureHtmlArtifact($submission, $documentData['document_type'], $documentData['html']);

            $document->set('label', sprintf('%s #%s', $documentData['label'], (string) $submission->id()));
            $document->set('submission_id', $submission->id());
            $document->set('document_type', $documentData['document_type']);
            $document->set('format', 'html');
            $document->set('version', 'layout-v1');
            $document->set('storage_path', $artifact['path']);
            $document->set('source_hash', hash('sha256', $documentData['html']));
            $document->set('metadata', [
                'summary' => $documentData['summary'],
                'submission_title' => $submission->label(),
                'filename' => $artifact['filename'],
                'size' => filesize($artifact['path']) ?: 0,
            ]);
            $document->set('generated_at', $generatedAt);
            $this->documentStorage->save($document);
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixA(array $canonical, array $source): string
    {
        $applicant = $canonical['applicant'] ?? [];
        $identity = $applicant['identity'] ?? [];
        $contact = $applicant['contact'] ?? [];
        $address = $contact['address'] ?? [];
        $education = $applicant['education'] ?? [];
        $employment = $applicant['employment'] ?? [];

        return $this->renderPanel(
            'Step 1: Client Data Form',
            'Appendix A — ISETP-001',
            'ISETP-001',
            'ISETP Policy 5.4',
            '2024-04-01',
            '4',
            [
                $this->renderFormSection('Client Information', [
                    $this->renderFieldGroup([
                        $this->field('SIN', $source['a_sin'] ?? ''),
                        $this->field('Full Legal Name', $identity['full_name'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Marital Status', $identity['marital_status'] ?? ''),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Number of Dependents', $this->stringify($identity['dependents_count'] ?? '')),
                        $this->field('Ages of Dependents', implode(', ', $identity['dependents_ages'] ?? [])),
                        $this->field('Birth Date', $identity['birth_date'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Gender', $identity['gender'] ?? ''),
                        $this->field('Disability', $this->boolLabel($identity['disability_status'] ?? null)),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Aboriginal Group', $identity['aboriginal_group'] ?? ''),
                        $this->field('Band #', $identity['band_number'] ?? ''),
                        $this->field('Band / Community', $identity['band_community'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Preferred Language', $identity['preferred_language'] ?? ''),
                        $this->field('Second Language', $identity['second_language'] ?? ''),
                        $this->field('Residence', $address['residence_type'] ?? ''),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Contact Information', [
                    $this->renderFieldGroup([
                        $this->field('PO Box', $address['po_box'] ?? ''),
                        $this->field('Street', $address['street'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Town', $address['town'] ?? ''),
                        $this->field('Province', $address['province'] ?? ''),
                        $this->field('Postal Code', $address['postal_code'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Telephone', $contact['telephone'] ?? ''),
                        $this->field('Email', $contact['email'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Emergency Contact', $contact['emergency_contact']['name'] ?? ''),
                        $this->field('Relationship', $contact['emergency_contact']['relationship'] ?? ''),
                        $this->field('Emergency Telephone', $contact['emergency_contact']['telephone'] ?? ''),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Education and Training', [
                    $this->renderFieldGroup([
                        $this->field('Elementary Completed', $this->boolLabel($education['elementary_completed'] ?? null)),
                        $this->field('High School Completed', $this->boolLabel($education['high_school_completed'] ?? null)),
                        $this->field('Highest Grade', $education['highest_grade'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Year Completed', $education['year_completed'] ?? ''),
                        $this->field('Licences / Certificates', implode(', ', $education['licenses_certificates'] ?? []), true),
                    ]),
                ]),
                $this->renderFormSection('Employment and Income', [
                    $this->renderFieldGroup([
                        $this->field('Employment Status', $employment['employment_status'] ?? ''),
                        $this->field('Last Employer', $employment['last_employer'] ?? ''),
                        $this->field('Job Title', $employment['job_title'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Employed From', $employment['employed_from'] ?? ''),
                        $this->field('Employed To', $employment['employed_to'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Income Sources', $employment['income_sources'] ?? [], true),
                        $this->field('Leaving Reasons', $employment['leaving_reasons'] ?? [], true),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Barriers', $employment['barriers'] ?? [], true),
                        $this->field('Other Barrier Notes', $employment['barriers_other'] ?? '', true),
                    ]),
                ]),
            ],
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixB(array $canonical, array $source): string
    {
        $consent = $canonical['consents']['appendix_b'] ?? [];

        $consentText = implode("\n\n", [
            'I hereby consent to the collection, use and disclosure of my personal information by the Indigenous Skills and Employment Training (ISET) Program holder and/or its agents for the purpose of determining my eligibility for, and administering, employment and training programs and services.',
            'I understand that my personal information may be shared with federal and provincial government departments and agencies, educational institutions, employers and other third parties for the purposes of determining eligibility, delivering services, monitoring outcomes, research and statistical purposes, and administering the program.',
            'I understand that this consent is voluntary and may be withdrawn in writing, and that withdrawal may affect eligibility for employment and training supports.',
        ]);

        return $this->renderPanel(
            'Step 2: Consent Form',
            'Appendix B — ISETP-002',
            'ISETP-002',
            'ISETP Policy 5.4',
            '2024-04-01',
            '1',
            [
                $this->renderFormSection('Consent to Collect, Use and Disclose Personal Information', [
                    sprintf('<div class="consent-text">%s</div>', nl2br($this->escape($consentText))),
                    $this->renderFieldGroup([
                        $this->field('Day', $source['b_day'] ?? ''),
                        $this->field('Month', $source['b_month'] ?? ''),
                        $this->field('Year', $source['b_year'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Print Full Name', $consent['signed_by'] ?? ($source['b_print_name'] ?? '')),
                        $this->field('Signature', $source['b_signature'] ?? ''),
                    ]),
                    '<h4>Parent / Guardian (for participants 15 and under)</h4>',
                    $this->renderFieldGroup([
                        $this->field('Parent / Guardian Name', $source['b_parent_name'] ?? ''),
                        $this->field('Parent / Guardian Signature', $source['b_parent_sig'] ?? ''),
                    ]),
                    '<h4>Witness</h4>',
                    $this->renderFieldGroup([
                        $this->field('Witness Full Name', $source['b_witness_name'] ?? ''),
                        $this->field('Witness Signature', $source['b_witness_sig'] ?? ''),
                    ]),
                    '<div class="info-box"><strong>Privacy Act Notice</strong><br>The personal information collected on this form is used for the administration of the Indigenous Skills and Employment Training Program and is handled in accordance with applicable privacy legislation.</div>',
                ]),
            ],
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixF(array $canonical, array $source): string
    {
        $funding = $canonical['funding_request'] ?? [];

        return $this->renderPanel(
            'Step 3: Request for Funding',
            'Appendix F — ISETP-006',
            'ISETP-006',
            'ISETP Policy 5.4',
            '2024-04-01',
            '2',
            [
                $this->renderFormSection('Applicant Information', [
                    $this->renderFieldGroup([
                        $this->field('Full Legal Name', $source['f_name'] ?? ($canonical['applicant']['identity']['full_name'] ?? '')),
                        $this->field('Telephone', $source['f_tel'] ?? ''),
                        $this->field('Email', $source['f_email'] ?? ($canonical['applicant']['contact']['email'] ?? '')),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Activity Type', [
                    $this->renderFieldGroup([
                        $this->field('Selected Activity', $funding['activity_type'] ?? ''),
                        $this->field('Job Opportunity Reasons', $this->checkedLabelsFromSource($source, 'check_f_job_reason_'), true),
                    ]),
                ]),
                $this->renderFormSection('Preferred Employer / Institution', [
                    $this->renderFieldGroup([
                        $this->field('Preferred Employer / Institution 1', $source['f_pref1'] ?? ''),
                        $this->field('Preferred Employer / Institution 2', $source['f_pref2'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Desired Time Frame — From', $funding['timeframe']['from'] ?? ''),
                        $this->field('Desired Time Frame — To', $funding['timeframe']['to'] ?? ''),
                    ]),
                ]),
                $this->renderFormSection('Career Goals & Training Plan', [
                    $this->renderFieldGroup([
                        $this->field('Overall Career Goals', $funding['career_goals'] ?? '', true),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('How does this activity support your career goals?', $funding['support_rationale'] ?? '', true),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Following this program, do you intend to', $source['radio_f_following'] ?? $funding['post_program_intent'] ?? ''),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Three-Year Training Plan', $funding['three_year_plan'] ?? '', true),
                    ], 'cols-1'),
                    '<h4>Certified Training Required</h4>',
                    $this->renderFieldGroup([
                        $this->field('1', $source['f_cert1'] ?? ''),
                        $this->field('2', $source['f_cert2'] ?? ''),
                        $this->field('3', $source['f_cert3'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('4', $source['f_cert4'] ?? ''),
                        $this->field('5', $source['f_cert5'] ?? ''),
                        $this->field('6', $source['f_cert6'] ?? ''),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Certification', [
                    $this->renderFieldGroup([
                        $this->field('Signature', $source['f_sig'] ?? ''),
                        $this->field('Date', $source['f_date'] ?? ''),
                    ]),
                ]),
            ],
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixG(array $canonical, array $source): string
    {
        $career = $canonical['career_plan'] ?? [];
        $jobs = $career['job_searches'] ?? [];
        $preferred = $career['institutions']['preferred'] ?? [];
        $alternate = $career['institutions']['alternate'] ?? [];

        $jobRows = '';
        foreach ($jobs as $index => $job) {
            $jobRows .= sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $index + 1,
                $this->escape($this->stringify($job['position'] ?? '')),
                $this->escape($this->stringify($job['employers'] ?? '')),
                $this->escape($this->stringify($job['responsibilities'] ?? '')),
                $this->escape($this->stringify(trim(($job['wage_start'] ?? '') . ' to ' . ($job['wage_top'] ?? '')))),
                $this->escape($this->stringify($job['location'] ?? '')),
            );
        }

        return $this->renderPanel(
            'Step 4: Career Action Plan',
            'Appendix G — ISETP-007',
            'ISETP-007',
            'ISETP Policy 5.4',
            '2024-04-01',
            '9',
            [
                $this->renderFormSection('Career Goal', [
                    sprintf('<div class="info-box">%s</div>', $this->escape('The Career Action Plan assists in exploring career options, identifying skills and training needs, and building a clear path to employment or self-employment.')),
                    $this->renderFieldGroup([
                        $this->field('Career Goal', $career['career_goal'] ?? ''),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Why this occupation or path?', $career['goal_reason'] ?? '', true),
                    ], 'cols-1'),
                ]),
                $this->renderFormSection('Job Market Research', [
                    sprintf('<table class="form-table"><thead><tr><th>#</th><th>Position</th><th>Employers / Market</th><th>Responsibilities</th><th>Compensation</th><th>Location</th></tr></thead><tbody>%s</tbody></table>', $jobRows),
                ]),
                $this->renderFormSection('Education, Skills, and Work Gap Analysis', [
                    $this->renderFieldGroup([
                        $this->field('Education / Training — Have', $source['g_jiw_edu_have'] ?? '', true),
                        $this->field('Education / Training — Need', $source['g_jiw_edu_need'] ?? '', true),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Licences / Certificates — Have', $source['g_jiw_lic_have'] ?? '', true),
                        $this->field('Licences / Certificates — Need', $source['g_jiw_lic_need'] ?? '', true),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Skills — Have', $source['g_jiw_skills_have'] ?? '', true),
                        $this->field('Skills — Need', $source['g_jiw_skills_need'] ?? '', true),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Work Experience — Have', $source['g_jiw_work_have'] ?? '', true),
                        $this->field('Work Experience — Need', $source['g_jiw_work_need'] ?? '', true),
                    ]),
                ]),
                $this->renderFormSection('Institution Research', [
                    '<h4>Preferred Institution</h4>',
                    $this->renderFieldGroup([
                        $this->field('Institution', $preferred['institution_name'] ?? ''),
                        $this->field('Course', $preferred['course_name'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Delivery', $preferred['delivery'] ?? ''),
                        $this->field('Length', $preferred['length'] ?? ''),
                        $this->field('Address', $preferred['address'] ?? ''),
                    ], 'cols-3'),
                    '<h4>Alternate Institution</h4>',
                    $this->renderFieldGroup([
                        $this->field('Institution', $alternate['institution_name'] ?? ''),
                        $this->field('Course', $alternate['course_name'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Delivery', $alternate['delivery'] ?? ''),
                        $this->field('Length', $alternate['length'] ?? ''),
                        $this->field('Address', $alternate['address'] ?? ''),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Financial Impact and Three-Year Plan', [
                    $this->renderFieldGroup([
                        $this->field('Tuition / Books / Travel Notes', implode("\n", array_filter([
                            $source['g_fi_tuition'] ?? '',
                            $source['g_fi_books'] ?? '',
                            $source['g_fi_travel'] ?? '',
                        ])), true),
                        $this->field('Living Expense Notes', implode("\n", array_filter([
                            $source['g_fi_rent'] ?? '',
                            $source['g_fi_food'] ?? '',
                            $source['g_fi_utilities'] ?? '',
                            $source['g_fi_childcare'] ?? '',
                        ])), true),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Three-Year Plan', $career['three_year_plan'] ?? '', true),
                    ], 'cols-1'),
                ]),
            ],
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixH(array $canonical, array $source): string
    {
        $release = $canonical['consents']['appendix_h'] ?? [];

        return $this->renderPanel(
            'Step 5: Authorization of Release',
            'Appendix H — ISETP-008',
            'ISETP-008',
            'ISETP Policy 5.4',
            '2024-04-01',
            '1',
            [
                $this->renderFormSection('Authorization to Release Academic Information', [
                    sprintf('<p>%s</p>', $this->escape('I hereby authorize the academic institution named below to release my academic records, including grades, transcripts, attendance records, and enrollment status, to the ISET Program holder for the purpose of monitoring my progress in the funded training or educational program.')),
                    $this->renderFieldGroup([
                        $this->field('Academic Institution Name', $release['institution'] ?? ''),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Student Name (Print)', $release['student_name'] ?? ($source['h_student_name'] ?? '')),
                        $this->field('Academic Year — From', $source['h_year_from'] ?? ''),
                        $this->field('Academic Year — To', $source['h_year_to'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Signature', $source['h_signature'] ?? ''),
                        $this->field('Date', $release['signed_at'] ?? ($source['h_date'] ?? '')),
                    ]),
                ]),
            ],
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $source
     */
    private function renderAppendixM(array $canonical, array $source): string
    {
        $business = $canonical['business'] ?? [];
        $identity = $business['identity'] ?? [];
        $operations = $business['operations'] ?? [];
        $ownership = $business['ownership'] ?? [];
        $financials = $business['financials'] ?? [];

        return $this->renderPanel(
            'Step 6: Business Proposal Summary',
            'Appendix M — ISETP-013',
            'ISETP-013',
            'ISETP Policy 5.4',
            '2024-04-01',
            '2',
            [
                $this->renderFormSection('Business Information', [
                    $this->renderFieldGroup([
                        $this->field('Legal Name of Applicant', $identity['legal_name'] ?? ''),
                        $this->field('Registered Name of Business', $identity['business_name'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Business Address', $source['m_biz_addr'] ?? ''),
                        $this->field('City / Town', $source['m_biz_city'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Province', $source['m_biz_prov'] ?? ''),
                        $this->field('Postal Code', $source['m_biz_postal'] ?? ''),
                        $this->field('Telephone', $source['m_biz_tel'] ?? ''),
                    ], 'cols-3'),
                    $this->renderFieldGroup([
                        $this->field('Proposed Business Location', $operations['location_type'] ?? ''),
                        $this->field('Form of Business Ownership', $ownership['ownership_model'] ?? ''),
                    ]),
                ]),
                $this->renderFormSection('Signing Officers / Shareholders', [
                    sprintf(
                        '<table class="form-table"><thead><tr><th>#</th><th>Print Name</th><th>Signature</th></tr></thead><tbody>%s</tbody></table>',
                        implode('', [
                            sprintf('<tr><td>1</td><td>%s</td><td>%s</td></tr>', $this->escape((string) ($source['m_sign1_name'] ?? '')), $this->escape((string) ($source['m_sign1_sig'] ?? ''))),
                            sprintf('<tr><td>2</td><td>%s</td><td>%s</td></tr>', $this->escape((string) ($source['m_sign2_name'] ?? '')), $this->escape((string) ($source['m_sign2_sig'] ?? ''))),
                            sprintf('<tr><td>3</td><td>%s</td><td>%s</td></tr>', $this->escape((string) ($source['m_sign3_name'] ?? '')), $this->escape((string) ($source['m_sign3_sig'] ?? ''))),
                            sprintf('<tr><td>4</td><td>%s</td><td>%s</td></tr>', $this->escape((string) ($source['m_sign4_name'] ?? '')), $this->escape((string) ($source['m_sign4_sig'] ?? ''))),
                        ]),
                    ),
                ]),
                $this->renderFormSection('Classification of Business', [
                    $this->renderFieldGroup([
                        $this->field('Classification', $identity['classification'] ?? '', true),
                        $this->field('Other / Additional Notes', $source['m_class_other'] ?? ''),
                    ]),
                ]),
                $this->renderFormSection('Business Status & Operations', [
                    $this->renderFieldGroup([
                        $this->field('Business Status', $operations['business_status'] ?? ''),
                        $this->field('Registration Date', $source['m_reg_date'] ?? ''),
                    ]),
                    $this->renderFieldGroup([
                        $this->field('Applicant Involvement', $operations['owner_involvement'] ?? ''),
                        $this->field('Hours of Operation', $operations['hours_of_operation'] ?? ''),
                        $this->field('Weekly Hours', $operations['weekly_hours'] ?? ''),
                    ], 'cols-3'),
                ]),
                $this->renderFormSection('Objectives', [
                    $this->renderFieldGroup([
                        $this->field('Business Objectives', $identity['description'] ?? '', true),
                    ], 'cols-1'),
                ]),
                $this->renderFormSection('Operating Requirements', [
                    $this->renderFieldGroup([
                        $this->field('Government Regulations / Permits / Licences Required', $operations['regulatory_requirements'] ?? [], true),
                    ], 'cols-1'),
                    $this->renderFieldGroup([
                        $this->field('Obtained?', $source['radio_m_obtained'] ?? ''),
                        $this->field('Anticipated Date of Obtaining', $operations['launch_timeline'] ?? ''),
                    ]),
                ]),
                $this->renderFormSection('Financials', [
                    $this->renderFieldGroup([
                        $this->field('Total Startup Cost ($)', $source['m_startup_cost'] ?? ''),
                    ], 'cols-1'),
                    sprintf(
                        '<table class="form-table"><thead><tr><th>Source</th><th>Amount ($)</th></tr></thead><tbody>%s</tbody></table>',
                        implode('', [
                            sprintf('<tr><td>Client Cash Equity</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_equity'] ?? ''))),
                            sprintf('<tr><td>Loan 1</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_loan1'] ?? ''))),
                            sprintf('<tr><td>Loan 2</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_loan2'] ?? ''))),
                            sprintf('<tr><td>Grant 1</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_grant1'] ?? ''))),
                            sprintf('<tr><td>Grant 2</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_grant2'] ?? ''))),
                            sprintf('<tr><td>Total</td><td>%s</td></tr>', $this->escape((string) ($source['m_fund_total'] ?? ''))),
                        ]),
                    ),
                    $this->renderFieldGroup([
                        $this->field('Funding Source Keys Captured', array_keys($financials['funding_sources'] ?? []), true),
                    ], 'cols-1'),
                ]),
            ],
        );
    }

    private function renderPackageCover(EntityInterface $submission): string
    {
        $startedAt = (string) ($submission->get('started_at') ?? '');
        $completion = $submission->get('completion_state');
        $completionCount = is_array($completion['appendices'] ?? null)
            ? count(array_filter($completion['appendices']))
            : 0;

        return sprintf(
            '<section class="panel"><div class="package-cover"><div class="eyebrow">Miikana</div><h2>ISET Self-Employment Assistance Package</h2><p>This package is rendered from canonical proposal state stored in Waaseyaa and seeded from the latest NorthOps application artifacts in ~/NorthOps.</p><div class="cover-grid"><div class="card"><strong>Submission</strong>%s</div><div class="card"><strong>Status</strong>%s</div><div class="card"><strong>Started</strong>%s</div><div class="card"><strong>Applicant</strong>%s</div><div class="card"><strong>Business</strong>%s</div><div class="card"><strong>Appendices Included</strong>%d of 6</div></div></div><div class="print-footer">%s</div></section>',
            $this->escape((string) ($submission->label() ?? '')),
            $this->escape((string) ($submission->get('status') ?? '')),
            $this->escape($startedAt),
            $this->escape((string) ($submission->get('applicant_name') ?? '')),
            $this->escape((string) ($submission->get('business_name') ?? '')),
            $completionCount,
            $this->escape(self::FOOTER),
        );
    }

    /**
     * @param list<string> $sections
     */
    private function renderPanel(
        string $title,
        string $appendixLabel,
        string $formNumber,
        string $policyRef,
        string $dateReviewed,
        string $pages,
        array $sections,
    ): string {
        return sprintf(
            '<section class="panel"><div class="panel-header"><h2>%s</h2><p>%s</p><div class="accent-line"></div></div><div class="form-meta"><span><strong>Form #:</strong> %s</span><span><strong>Policy Ref:</strong> %s</span><span><strong>Date Reviewed:</strong> %s</span><span><strong>Pages:</strong> %s</span></div>%s<div class="print-footer">%s</div></section>',
            $this->escape($title),
            $this->escape($appendixLabel),
            $this->escape($formNumber),
            $this->escape($policyRef),
            $this->escape($dateReviewed),
            $this->escape($pages),
            implode('', array_filter($sections)),
            $this->escape(self::FOOTER),
        );
    }

    /**
     * @param list<string> $parts
     */
    private function renderFormSection(string $title, array $parts): string
    {
        return sprintf(
            '<div class="form-section"><h3>%s</h3>%s</div>',
            $this->escape($title),
            implode('', array_filter($parts)),
        );
    }

    /**
     * @param list<string> $fields
     */
    private function renderFieldGroup(array $fields, string $class = ''): string
    {
        $class = trim('field-group ' . $class);
        return sprintf('<div class="%s">%s</div>', $this->escape($class), implode('', array_filter($fields)));
    }

    private function field(string $label, mixed $value, bool $multiline = false): string
    {
        $class = $multiline ? 'text-box' : 'value-box';

        if (is_array($value)) {
            $content = $value === [] ? '&nbsp;' : sprintf(
                '<div class="chips">%s</div>',
                implode('', array_map(
                    fn (mixed $item): string => sprintf('<span class="chip">%s</span>', $this->escape($this->stringify($item))),
                    $value,
                )),
            );
        } else {
            $text = trim($this->stringify($value));
            $content = $text === '' ? '&nbsp;' : nl2br($this->escape($text));
        }

        return sprintf('<div class="field"><label>%s</label><div class="%s">%s</div></div>', $this->escape($label), $class, $content);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<string>
     */
    private function checkedLabelsFromSource(array $source, string $prefix): array
    {
        $labels = [];
        foreach ($source as $key => $value) {
            if (!str_starts_with((string) $key, $prefix) || $value !== true) {
                continue;
            }

            $labels[] = str_replace('_', ' ', substr((string) $key, strlen($prefix)));
        }

        return $labels;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function filenameFor(string $documentType): string
    {
        return match ($documentType) {
            'appendix_a_preview' => 'appendix-a.html',
            'appendix_b_preview' => 'appendix-b.html',
            'appendix_f_preview' => 'appendix-f.html',
            'appendix_g_preview' => 'appendix-g.html',
            'appendix_h_preview' => 'appendix-h.html',
            'appendix_m_preview' => 'appendix-m.html',
            'merged_package_preview' => 'merged-package.html',
            default => preg_replace('/[^a-z0-9\-]+/', '-', strtolower($documentType)) . '.html',
        };
    }

    private function wrapArtifactHtml(string $documentType, string $body): string
    {
        $title = $documentType === 'merged_package_preview' ? 'Merged ISET Package' : strtoupper(str_replace('_preview', '', $documentType));

        return sprintf(
            <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>%s</title>
  <style>%s</style>
</head>
<body>
  <main>
    <div class="stack">%s</div>
  </main>
</body>
</html>
HTML,
            $this->escape($title),
            $this->pageStyles(),
            $body,
        );
    }

    private function boolLabel(?bool $value): string
    {
        return match ($value) {
            true => 'Yes',
            false => 'No',
            default => '',
        };
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->stringify($item), $value));
        }

        return trim((string) $value);
    }
}
