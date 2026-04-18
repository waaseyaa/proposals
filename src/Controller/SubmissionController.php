<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class SubmissionController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
    ) {}

    public function index(): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $ids = $storage->getQuery()
            ->sort('id', 'ASC')
            ->execute();
        $submissions = $storage->loadMultiple($ids);

        $items = '';
        foreach ($submissions as $submission) {
            $items .= $this->renderListItem($submission);
        }

        if ($items === '') {
            $items = <<<'HTML'
<div class="empty">
  <p>No proposal submissions are stored yet.</p>
  <p>Run <code>php bin/waaseyaa northops:seed</code> to import the latest package from <code>~/NorthOps</code>.</p>
</div>
HTML;
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Submissions · Miikana</title>
  <style>
    :root {
      --ink: #171411;
      --paper: #fffdfa;
      --sand: #f2eadf;
      --line: #dbccb8;
      --moss: #315845;
      --rust: #ba5a2d;
      --muted: #6f665c;
    }
    body {
      margin: 0;
      font-family: Georgia, serif;
      color: var(--ink);
      background: linear-gradient(180deg, #f8f3eb 0%, #efe5d8 100%);
    }
    main {
      max-width: 980px;
      margin: 0 auto;
      padding: 40px 24px 64px;
    }
    a { color: var(--moss); }
    .card {
      background: rgba(255, 253, 250, 0.94);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 10px 30px rgba(76, 62, 44, 0.06);
    }
    .grid {
      display: grid;
      grid-template-columns: 1.3fr 0.7fr;
      gap: 24px;
    }
    .list {
      display: grid;
      gap: 16px;
      margin-top: 24px;
    }
    .item {
      background: #fffaf2;
      border: 1px solid var(--line);
      border-radius: 16px;
      padding: 18px;
    }
    .item h2 {
      margin: 0 0 10px;
      font-size: 1.35rem;
    }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 10px 0 0;
      font-size: 0.92rem;
      color: var(--muted);
    }
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(49, 88, 69, 0.08);
      color: var(--moss);
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-size: 0.7rem;
    }
    .empty {
      border: 1px dashed var(--line);
      border-radius: 16px;
      padding: 20px;
      background: #fffaf5;
    }
    h1 {
      margin: 6px 0 12px;
      font-size: clamp(2rem, 4vw, 3.2rem);
      letter-spacing: -0.04em;
    }
    .eyebrow {
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 0.72rem;
      color: var(--rust);
      font-weight: 700;
    }
    p, li { line-height: 1.8; color: var(--muted); }
    ul { padding-left: 18px; }
    code {
      background: #f3ede5;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 2px 6px;
      color: var(--ink);
    }
    @media (max-width: 860px) {
      .grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <div class="grid">
      <section class="card">
        <div class="eyebrow">Submission Workspace</div>
        <h1>ISET proposal submissions are now a real entity-backed surface.</h1>
        <p>
          This view is backed by <code>proposal_submission</code> entities. The NorthOps seed importer
          hydrates canonical data and a source-form snapshot from the latest ISET package in <code>~/NorthOps</code>.
        </p>
        <div class="list">__SUBMISSION_ITEMS__</div>
      </section>
      <aside class="card">
        <div class="eyebrow">Current Surface</div>
        <ul>
          <li>NorthOps seed command available</li>
          <li>Canonical submission JSON persisted per entity</li>
          <li>Original form values retained for mapping work</li>
          <li>Per-submission detail route available</li>
        </ul>
        <p><a href="/">Back to dashboard</a></p>
      </aside>
    </div>
  </main>
</body>
</html>
HTML;

        $html = str_replace('__SUBMISSION_ITEMS__', $items, $html);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function show(int|string $submissionId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $canonical = json_encode($submission->get('canonical_data') ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $source = json_encode($submission->get('source_form_data') ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $reviewSummary = $this->reviewService->summarizeSubmission($submission);
        $readinessSummary = $this->buildReadinessSummary($submission);
        $appendixChecklist = $this->renderAppendixChecklist(
            is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [],
        );
        $appendixNotes = $this->reviewService->latestAppendixNotes($submission->id());
        $appendixNoteActivity = $this->reviewService->latestAppendixNoteActivity($submission->id());
        $appendixNotesPanel = $this->renderAppendixNotesPanel($appendixNotes, $appendixNoteActivity);
        $reviewPanel = $this->renderReviewPanel($reviewSummary, (string) $submission->id());
        $readinessAction = $this->renderReadinessAction($readinessSummary, (string) $submission->id(), $_SERVER['REQUEST_URI'] ?? '');
        $artifactAuditPanel = $this->renderArtifactAuditPanel(
            $this->artifactAuditService->summarize($submission),
            (string) $submission->id(),
        );
        $confidencePanel = $this->renderConfidencePanel(
            is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
            is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [],
        );
        $readinessPanel = $this->renderReadinessPanel($readinessSummary);
        $editPanel = $this->renderCanonicalEditPanel(
            is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [],
            (string) $submission->id(),
            (string) ($_GET['edit_path'] ?? ''),
            (string) ($_GET['edit_format'] ?? 'string'),
            $this->editNoticeFromUri($_SERVER['REQUEST_URI'] ?? ''),
        );
        $fieldReviewPanel = $this->renderFieldReviewPanel(
            $this->reviewService->fieldAnnotations($submission->id()),
            is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [],
            (string) $submission->id(),
        );
        $researchPanel = $this->renderResearchPanel(
            is_array($submission->get('research_log')) ? $submission->get('research_log') : [],
            is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
            (string) $submission->id(),
        );
        $researchBackedPanel = $this->renderAppliedResearchDraftsPanel(
            is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
            (string) $submission->id(),
        );

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Submission Detail · Miikana</title>
  <style>
    :root {
      --ink: #171411;
      --paper: #fffdfa;
      --sand: #f2eadf;
      --line: #dbccb8;
      --moss: #315845;
      --rust: #ba5a2d;
      --muted: #6f665c;
      --code: #1d2431;
      --code-ink: #ecf2fc;
    }
    body {
      margin: 0;
      font-family: Georgia, serif;
      color: var(--ink);
      background: linear-gradient(180deg, #f8f3eb 0%, #efe5d8 100%);
    }
    main {
      max-width: 1120px;
      margin: 0 auto;
      padding: 40px 24px 64px;
    }
    a { color: var(--moss); }
    .header {
      margin-bottom: 24px;
    }
    .eyebrow {
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 0.72rem;
      color: var(--rust);
      font-weight: 700;
    }
    h1 {
      margin: 8px 0 10px;
      font-size: clamp(2rem, 4vw, 3.2rem);
      letter-spacing: -0.04em;
    }
    p { color: var(--muted); line-height: 1.8; }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 14px;
      color: var(--muted);
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
    }
    .full {
      grid-column: 1 / -1;
    }
    .card {
      background: rgba(255, 253, 250, 0.94);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 10px 30px rgba(76, 62, 44, 0.06);
    }
    pre {
      margin: 12px 0 0;
      padding: 18px;
      border-radius: 14px;
      overflow: auto;
      background: var(--code);
      color: var(--code-ink);
      font-size: 0.83rem;
      line-height: 1.6;
    }
    .review-summary {
      display: grid;
      gap: 12px;
    }
    .review-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      color: var(--muted);
      font-size: 0.95rem;
    }
    .review-note {
      border-left: 4px solid var(--rust);
      padding-left: 14px;
      color: var(--ink);
    }
    .review-note strong {
      display: block;
      margin-bottom: 6px;
    }
    .edit-layout {
      display: grid;
      grid-template-columns: 0.85fr 1.15fr;
      gap: 16px;
      align-items: start;
    }
    .edit-layout form {
      display: grid;
      gap: 12px;
    }
    .edit-layout label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
    }
    .edit-layout input,
    .edit-layout select,
    .edit-layout textarea,
    .edit-layout button {
      font: inherit;
    }
    .edit-layout input,
    .edit-layout select,
    .edit-layout textarea {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: #fff;
      color: var(--ink);
    }
    .edit-layout textarea {
      min-height: 140px;
      resize: vertical;
    }
    .edit-layout button {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: var(--moss);
      color: #fff;
      cursor: pointer;
    }
    .edit-note {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffefd;
    }
    .edit-notice {
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(33, 102, 58, 0.18);
      background: rgba(33, 102, 58, 0.08);
      color: #21663a;
      font-weight: 700;
      margin-bottom: 12px;
    }
    .annotation-list {
      display: grid;
      gap: 14px;
      margin-top: 12px;
    }
    .annotation {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffefd;
    }
    .annotation-path {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.84rem;
      color: var(--moss);
      margin-bottom: 8px;
    }
    .annotation-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 0.88rem;
      color: var(--muted);
      margin-bottom: 8px;
    }
    .annotation-value {
      margin-top: 10px;
      padding: 12px 14px;
      border-radius: 12px;
      background: #f8f2ea;
      border: 1px solid var(--line);
      color: var(--ink);
      white-space: pre-wrap;
      word-break: break-word;
    }
    .annotation-empty {
      border: 1px dashed var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffaf5;
      color: var(--muted);
    }
    .readiness-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    .readiness-card {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
      background: #fffefd;
    }
    .readiness-card strong {
      display: block;
      margin-bottom: 6px;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
    }
    .readiness-note {
      margin-top: 12px;
      border-left: 4px solid var(--moss);
      padding-left: 14px;
      color: var(--ink);
    }
    .readiness-action {
      margin-top: 14px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }
    .readiness-action form {
      margin: 0;
    }
    .readiness-action button {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: var(--moss);
      color: #fff;
      font: inherit;
      cursor: pointer;
    }
    .readiness-action .notice {
      margin: 0;
      padding: 8px 12px;
      border-radius: 10px;
      background: rgba(33, 102, 58, 0.08);
      border: 1px solid rgba(33, 102, 58, 0.18);
      color: #21663a;
      font-weight: 700;
    }
    .checklist {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    .checklist-item {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
      background: #fffefd;
    }
    .checklist-item strong {
      display: block;
      margin-bottom: 6px;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
    }
    .confidence-list {
      display: grid;
      gap: 14px;
      margin-top: 12px;
    }
    .confidence-item {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffefd;
    }
    .confidence-item.weak {
      border-color: rgba(186, 90, 45, 0.4);
      background: #fff8f2;
    }
    .confidence-item strong {
      display: block;
      margin-bottom: 8px;
    }
    .confidence-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 0.88rem;
      color: var(--muted);
      margin-bottom: 8px;
    }
    .risk {
      color: var(--rust);
      font-weight: 700;
    }
    .pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(186, 90, 45, 0.1);
      color: var(--rust);
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    @media (max-width: 920px) {
      .grid { grid-template-columns: 1fr; }
      .full { grid-column: auto; }
      .edit-layout { grid-template-columns: 1fr; }
      .readiness-grid { grid-template-columns: 1fr 1fr; }
      .checklist { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <div class="header">
      <div class="eyebrow">Submission Detail</div>
      <h1>__TITLE__</h1>
      <p>The canonical proposal state and the raw imported HTML form snapshot are both visible here so the mapper can evolve without losing source fidelity.</p>
      <div class="meta">
        <span>Status: __STATUS__</span>
        <span>Applicant: __APPLICANT__</span>
        <span>Business: __BUSINESS__</span>
      </div>
        <p><a href="/submissions">Back to submissions</a> · <a href="/cohorts">Cohorts</a> · <a href="/submissions/__ID__/intake">Intake</a> · <a href="/submissions/__ID__/documents">Appendix documents</a> · <a href="/submissions/__ID__/package">Merged package</a> · <a href="/submissions/__ID__/package/pdf">PDF</a> · <a href="/submissions/__ID__/exports/bundle/download">Bundle ZIP</a> · <a href="/submissions/__ID__/exports">Exports</a> · <a href="/submissions/__ID__/review">Review</a></p>
    </div>
    <div class="grid">
      <section class="card full">
        <div class="eyebrow">Review Summary</div>
        __REVIEW_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Readiness</div>
        __READINESS_PANEL____READINESS_ACTION__
      </section>
      <section class="card full">
        <div class="eyebrow">Package Checklist</div>
        __APPENDIX_CHECKLIST__
      </section>
      <section class="card full">
        <div class="eyebrow">Artifact Audit</div>
        __ARTIFACT_AUDIT_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Recent Research</div>
        __RESEARCH_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Research-Backed Field Updates</div>
        __RESEARCH_BACKED_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Appendix Review Notes</div>
        __APPENDIX_NOTES_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Field Confidence</div>
        __CONFIDENCE_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Canonical Edit</div>
        __EDIT_PANEL__
      </section>
      <section class="card full">
        <div class="eyebrow">Field Review Notes</div>
        __FIELD_REVIEW_PANEL__
      </section>
      <section class="card">
        <div class="eyebrow">Canonical Data</div>
        <pre>__CANONICAL__</pre>
      </section>
      <section class="card">
        <div class="eyebrow">Imported Form Snapshot</div>
        <pre>__SOURCE__</pre>
      </section>
    </div>
  </main>
</body>
</html>
HTML;

        $replacements = [
            '__TITLE__' => htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__STATUS__' => htmlspecialchars((string) ($submission->get('status') ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__APPLICANT__' => htmlspecialchars((string) ($submission->get('applicant_name') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__BUSINESS__' => htmlspecialchars((string) ($submission->get('business_name') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__CANONICAL__' => htmlspecialchars((string) $canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__SOURCE__' => htmlspecialchars((string) $source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__ID__' => htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__REVIEW_PANEL__' => $reviewPanel,
            '__READINESS_PANEL__' => $readinessPanel,
            '__READINESS_ACTION__' => $readinessAction,
            '__APPENDIX_CHECKLIST__' => $appendixChecklist,
            '__ARTIFACT_AUDIT_PANEL__' => $artifactAuditPanel,
            '__RESEARCH_PANEL__' => $researchPanel,
            '__RESEARCH_BACKED_PANEL__' => $researchBackedPanel,
            '__APPENDIX_NOTES_PANEL__' => $appendixNotesPanel,
            '__CONFIDENCE_PANEL__' => $confidencePanel,
            '__EDIT_PANEL__' => $editPanel,
            '__FIELD_REVIEW_PANEL__' => $fieldReviewPanel,
        ];

        return new Response(str_replace(array_keys($replacements), array_values($replacements), $html), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function markReadyForReview(int|string $submissionId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $summary = $this->buildReadinessSummary($submission);
        if ($summary['label'] !== 'Ready for review') {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=not-ready');
        }

        $this->reviewService->transitionStatus(
            $submissionId,
            'ready_for_review',
            'Submission marked ready for staff review from the structured data workspace.',
        );

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?status=ready-for-review');
    }

    public function updateCanonical(Request $request, int|string $submissionId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $fieldPath = trim((string) $request->request->get('field_path', ''));
        $valueFormat = trim((string) $request->request->get('value_format', 'string'));
        $rawValue = (string) $request->request->get('field_value', '');

        if ($fieldPath === '') {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=canonical&edit_path=' . rawurlencode($fieldPath));
        }

        $canonicalData = is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [];

        try {
            $parsedValue = $this->parseCanonicalValue($rawValue, $valueFormat);
            $this->setValueAtPath($canonicalData, $fieldPath, $parsedValue);
            $submission->set('canonical_data', $canonicalData);
            $storage->save($submission);
        } catch (\Throwable $e) {
            return new RedirectResponse(
                '/submissions/' . rawurlencode((string) $submissionId)
                . '?error=canonical'
                . '&edit_path=' . rawurlencode($fieldPath)
                . '&edit_format=' . rawurlencode($valueFormat)
            );
        }

        return new RedirectResponse(
            '/submissions/' . rawurlencode((string) $submissionId)
            . '?updated=canonical'
            . '&edit_path=' . rawurlencode($fieldPath)
            . '&edit_format=' . rawurlencode($valueFormat)
        );
    }

    public function createResearchDraft(Request $request, int|string $submissionId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $researchIndex = filter_var((string) $request->request->get('research_index', ''), FILTER_VALIDATE_INT);
        $targetPath = trim((string) $request->request->get('target_path', ''));

        if ($researchIndex === false || $targetPath === '') {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-draft');
        }

        $researchLog = is_array($submission->get('research_log')) ? $submission->get('research_log') : [];
        $researchItem = $researchLog[$researchIndex] ?? null;
        if (!is_array($researchItem) || !isset($researchItem['query'])) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-draft');
        }

        $allowedTargets = array_keys($this->researchDraftTargetOptions());
        if (!in_array($targetPath, $allowedTargets, true)) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-draft');
        }

        $assessment = $this->assessResearchItem($researchItem);
        if (!$assessment['draftable']) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-draft-ungrounded');
        }

        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        foreach ($drafts as $existingDraft) {
            if (!is_array($existingDraft)) {
                continue;
            }

            $matchesSource = (int) ($existingDraft['research_index'] ?? -1) === (int) $researchIndex
                && (string) ($existingDraft['target_path'] ?? '') === $targetPath;
            $isActive = in_array((string) ($existingDraft['status'] ?? ''), ['pending', 'applied'], true);

            if ($matchesSource && $isActive) {
                return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?updated=research-draft-existing');
            }
        }

        $draftId = gmdate('YmdHis') . '-' . substr(sha1((string) $researchIndex . '|' . $targetPath . '|' . ((string) ($researchItem['query'] ?? ''))), 0, 8);

        $drafts[] = [
            'id' => $draftId,
            'status' => 'pending',
            'created_at' => gmdate(DATE_ATOM),
            'research_index' => (int) $researchIndex,
            'source_kind' => (string) ($researchItem['kind'] ?? 'research'),
            'source_query' => (string) ($researchItem['query'] ?? ''),
            'source_provider' => (string) ($researchItem['provider'] ?? ''),
            'source_status' => (string) ($researchItem['status'] ?? 'unknown'),
            'target_path' => $targetPath,
            'suggested_value' => $this->buildResearchDraftValue($researchItem, $targetPath),
            'source_summary' => (string) ($researchItem['summary'] ?? ''),
            'draft_quality' => (string) $assessment['label'],
            'citations' => is_array($researchItem['citations'] ?? null) ? $researchItem['citations'] : [],
        ];

        $validationState['research_drafts'] = $drafts;
        $submission->set('validation_state', $validationState);
        $storage->save($submission);

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?updated=research-draft');
    }

    public function applyResearchDraft(int|string $submissionId, string $draftId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        $canonicalData = is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [];

        $selectedTargetPath = null;
        foreach ($drafts as &$draft) {
            if (!is_array($draft) || (string) ($draft['id'] ?? '') !== $draftId) {
                continue;
            }

            if (($draft['status'] ?? 'pending') !== 'pending') {
                continue;
            }

            $assessment = $this->assessResearchItem([
                'provider' => $draft['source_provider'] ?? '',
                'status' => $draft['source_status'] ?? '',
                'citations' => $draft['citations'] ?? [],
                'summary' => $draft['source_summary'] ?? '',
            ]);
            if (!$assessment['draftable']) {
                return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-apply');
            }

            $targetPath = (string) ($draft['target_path'] ?? '');
            $selectedTargetPath = $targetPath;
            $draft['previous_value'] = $this->valueToDraftString($this->getValueAtPath($canonicalData, $targetPath));
            $this->setValueAtPath($canonicalData, $targetPath, (string) ($draft['suggested_value'] ?? ''));
            $draft['status'] = 'applied';
            $draft['applied_at'] = gmdate(DATE_ATOM);
        }
        unset($draft);

        if ($selectedTargetPath !== null) {
            foreach ($drafts as &$draft) {
                if (!is_array($draft) || (string) ($draft['id'] ?? '') === $draftId) {
                    continue;
                }

                if ((string) ($draft['target_path'] ?? '') !== $selectedTargetPath) {
                    continue;
                }

                if ((string) ($draft['status'] ?? '') !== 'applied') {
                    continue;
                }

                $draft['status'] = 'superseded';
                $draft['superseded_at'] = gmdate(DATE_ATOM);
            }
            unset($draft);
        }

        $validationState['research_drafts'] = $drafts;
        $submission->set('canonical_data', $canonicalData);
        $submission->set('validation_state', $validationState);
        $storage->save($submission);

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?updated=research-applied');
    }

    public function restoreResearchDraft(int|string $submissionId, string $draftId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        $canonicalData = is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [];
        $restored = false;

        foreach ($drafts as &$draft) {
            if (!is_array($draft) || (string) ($draft['id'] ?? '') !== $draftId) {
                continue;
            }

            if (($draft['status'] ?? '') !== 'applied' || !array_key_exists('previous_value', $draft)) {
                continue;
            }

            $this->setValueAtPath($canonicalData, (string) ($draft['target_path'] ?? ''), (string) ($draft['previous_value'] ?? ''));
            $draft['status'] = 'restored';
            $draft['restored_at'] = gmdate(DATE_ATOM);
            $restored = true;
        }
        unset($draft);

        if (!$restored) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?error=research-restore');
        }

        $validationState['research_drafts'] = $drafts;
        $submission->set('canonical_data', $canonicalData);
        $submission->set('validation_state', $validationState);
        $storage->save($submission);

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?updated=research-restored');
    }

    public function rejectResearchDraft(int|string $submissionId, string $draftId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new RedirectResponse('/submissions');
        }

        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];

        foreach ($drafts as &$draft) {
            if (!is_array($draft) || (string) ($draft['id'] ?? '') !== $draftId) {
                continue;
            }

            $draft['status'] = 'rejected';
            $draft['rejected_at'] = gmdate(DATE_ATOM);
        }
        unset($draft);

        $validationState['research_drafts'] = $drafts;
        $submission->set('validation_state', $validationState);
        $storage->save($submission);

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '?updated=research-rejected');
    }

    private function renderListItem(EntityInterface $submission): string
    {
        $reviewSummary = $this->reviewService->summarizeSubmission($submission);
        $cohortMeta = '';
        $cohortId = (int) ($submission->get('cohort_id') ?? 0);
        if ($cohortId > 0) {
            $cohort = $this->entityTypeManager->getStorage('proposal_cohort')->load($cohortId);
            if ($cohort instanceof EntityInterface) {
                $cohortMeta = sprintf(
                    '<span>Cohort: <a href="/cohorts/%s">%s</a></span>',
                    rawurlencode((string) $cohort->id()),
                    htmlspecialchars((string) ($cohort->label() ?? 'Cohort'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
        }
        $title = htmlspecialchars((string) ($submission->label() ?? 'Untitled Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = htmlspecialchars((string) ($submission->get('status') ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $applicant = htmlspecialchars((string) ($submission->get('applicant_name') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $business = htmlspecialchars((string) ($submission->get('business_name') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = '/submissions/' . rawurlencode((string) $submission->id());
        $confidenceSummary = $this->summarizeConfidenceState(is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : []);
        $readinessSummary = $this->buildReadinessSummary($submission);
        $reviewMeta = sprintf(
            'Reviews: %d · Reviewed appendices: %d/%d%s',
            (int) $reviewSummary['review_count'],
            (int) $reviewSummary['reviewed_appendix_count'],
            (int) $reviewSummary['reviewed_appendix_total'],
            ($reviewSummary['latest_created_at'] ?? null) ? ' · Latest: ' . htmlspecialchars((string) $reviewSummary['latest_created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '',
        );
        $reviewCallout = '';

        if (($reviewSummary['latest_comment'] ?? null) && $reviewSummary['has_revisions_requested']) {
            $reviewCallout = sprintf(
                '<p><span class="badge" style="background: rgba(186, 90, 45, 0.12); color: #ba5a2d;">Revision Note</span> %s</p>',
                htmlspecialchars((string) $reviewSummary['latest_comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }
        if ($confidenceSummary['weak_count'] > 0) {
            $reviewCallout .= sprintf(
                '<p><span class="badge" style="background: rgba(186, 90, 45, 0.12); color: #ba5a2d;">Low Confidence</span> %d %s.</p>',
                $confidenceSummary['weak_count'],
                $confidenceSummary['weak_count'] === 1 ? 'field needs follow-up' : 'fields need follow-up',
            );
        }
        $reviewCallout .= sprintf(
            '<p><span class="badge" style="background: rgba(49, 88, 69, 0.12); color: #315845;">Readiness</span> %s</p>',
            htmlspecialchars($readinessSummary['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        return sprintf(
            '<article class="item"><span class="badge">%s</span><h2><a href="%s">%s</a></h2><p>Canonical proposal state and imported source form data are attached to this submission.</p>%s<div class="meta"><span>ID %s</span><span>Applicant: %s</span><span>Business: %s</span>%s<span>%s</span><span><a href="%s/intake">Intake</a></span><span><a href="%s/documents">Documents</a></span><span><a href="%s/package">Package</a></span><span><a href="%s/package/pdf">PDF</a></span><span><a href="%s/exports/bundle/download">Bundle ZIP</a></span><span><a href="%s/exports">Exports</a></span><span><a href="%s/review">Review</a></span></div></article>',
            $status,
            $url,
            $title,
            $reviewCallout,
            htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $applicant,
            $business,
            $cohortMeta,
            $reviewMeta,
            $url,
            $url,
            $url,
            $url,
            $url,
            $url,
            $url,
        );
    }

    /**
     * @param array{review_count:int,latest_action:?string,latest_title:?string,latest_comment:?string,latest_section:?string,latest_field:?string,latest_created_at:?string,status:string,current_step:string,has_revisions_requested:bool,is_approved:bool,reviewed_appendix_count:int,reviewed_appendix_total:int} $reviewSummary
     */
    private function renderReviewPanel(array $reviewSummary, string $submissionId): string
    {
        $latestComment = trim((string) ($reviewSummary['latest_comment'] ?? ''));
        $latestTitle = trim((string) ($reviewSummary['latest_title'] ?? 'Latest review activity'));
        $latestSection = trim((string) ($reviewSummary['latest_section'] ?? ''));
        $latestField = trim((string) ($reviewSummary['latest_field'] ?? ''));
        $latestCreatedAt = trim((string) ($reviewSummary['latest_created_at'] ?? ''));
        $notes = $latestComment !== ''
            ? sprintf(
                '<div class="review-note"><strong>%s</strong><div>%s</div></div>',
                htmlspecialchars($latestTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($latestComment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            )
            : '<div class="review-note"><strong>No review notes yet.</strong><div>Staff review activity will appear here once comments or status changes are recorded.</div></div>';

        $revisionPill = $reviewSummary['has_revisions_requested']
            ? '<span class="pill">Revisions Requested</span>'
            : ($reviewSummary['is_approved'] ? '<span class="pill">Approved Track</span>' : '');

        return sprintf(
            '<div class="review-summary"><div class="review-strip"><span>Status: %s</span><span>Current step: %s</span><span>Review actions: %d</span><span>Appendices reviewed: %d/%d</span><span>Section: %s</span><span>Field: %s</span><span>Updated: %s</span>%s</div>%s<p><a href="/submissions/%s/review">Open full review cockpit</a></p></div>',
            htmlspecialchars((string) $reviewSummary['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) $reviewSummary['current_step'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            (int) $reviewSummary['review_count'],
            (int) $reviewSummary['reviewed_appendix_count'],
            (int) $reviewSummary['reviewed_appendix_total'],
            htmlspecialchars($latestSection !== '' ? $latestSection : 'n/a', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($latestField !== '' ? $latestField : 'n/a', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($latestCreatedAt !== '' ? $latestCreatedAt : 'n/a', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $revisionPill,
            $notes,
            htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param array{ready_count:int,total_count:int,missing:list<string>,items:list<array{document_type:string,label:string,ready:bool}>} $artifactAudit
     */
    private function renderArtifactAuditPanel(array $artifactAudit, string $submissionId): string
    {
        $items = '';
        foreach ($artifactAudit['items'] as $item) {
            $items .= sprintf(
                '<div class="checklist-item"><strong>%s</strong>%s</div>',
                htmlspecialchars((string) $item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $item['ready'] ? 'Ready' : 'Missing',
            );
        }

        $warning = $artifactAudit['missing'] === []
            ? '<div class="annotation-empty">All expected HTML, PDF, and bundle artifacts are present on disk.</div>'
            : sprintf(
                '<div class="annotation-empty">Missing artifacts: %s</div>',
                htmlspecialchars(implode(' · ', $artifactAudit['missing']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

        return sprintf(
            '<div class="readiness-grid"><div class="readiness-card"><strong>Artifacts Ready</strong>%d/%d</div><div class="readiness-card"><strong>Missing</strong>%d</div><div class="readiness-card"><strong>Action</strong><a href="/submissions/%s/exports">Open exports</a></div><div class="readiness-card"><strong>Bundle</strong><a href="/submissions/%s/exports/bundle/download">Download</a></div></div>%s<div class="checklist">%s</div>',
            (int) $artifactAudit['ready_count'],
            (int) $artifactAudit['total_count'],
            count($artifactAudit['missing']),
            htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $warning,
            $items,
        );
    }

    /**
     * @param array<string, mixed> $confidenceState
     * @param array<string, mixed> $canonicalData
     */
    private function renderConfidencePanel(array $confidenceState, array $canonicalData): string
    {
        $entries = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence'])) {
                continue;
            }

            $confidence = (float) $state['confidence'];
            $resolved = (bool) ($state['resolved'] ?? true);
            $note = (string) ($state['note'] ?? '');
            $updatedAt = (string) ($state['updated_at'] ?? '');
            $value = $this->renderAnnotationValue($this->valueAtPath($canonicalData, (string) $path));

            $entries[] = sprintf(
                '<article class="confidence-item %s"><strong>%s</strong><div class="confidence-meta"><span>confidence %.2f</span><span class="%s">%s</span><span>%s</span></div><div>%s</div><div class="annotation-value">%s</div></article>',
                $resolved ? '' : 'weak',
                htmlspecialchars((string) $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $confidence,
                $resolved ? '' : 'risk',
                $resolved ? 'resolved' : 'follow-up needed',
                htmlspecialchars($updatedAt !== '' ? $updatedAt : 'n/a', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($note !== '' ? $note : 'No confidence note recorded.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        if ($entries === []) {
            return '<div class="annotation-empty">No intake confidence metadata recorded yet. New intake turns will appear here with confidence and follow-up notes.</div>';
        }

        return '<div class="confidence-list">' . implode('', $entries) . '</div>';
    }

    /**
     * @param array<string, mixed> $confidenceState
     * @return array{weak_count:int,total_count:int}
     */
    private function summarizeConfidenceState(array $confidenceState): array
    {
        $weakCount = 0;
        $totalCount = 0;

        foreach ($confidenceState as $state) {
            if (!is_array($state) || !isset($state['confidence'])) {
                continue;
            }

            $totalCount++;
            if (($state['resolved'] ?? true) === false) {
                $weakCount++;
            }
        }

        return [
            'weak_count' => $weakCount,
            'total_count' => $totalCount,
        ];
    }

    private function buildReadinessSummary(EntityInterface $submission): array
    {
        $status = (string) ($submission->get('status') ?? 'draft');
        $currentStep = (string) ($submission->get('current_step') ?? '');
        $unresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $confidenceSummary = $this->summarizeConfidenceState(
            is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
        );

        $label = match (true) {
            $status === 'submitted' => 'Submitted',
            $status === 'exported' => 'Exported',
            $status === 'approved' && $confidenceSummary['weak_count'] === 0 && $unresolved === [] => 'Approved and clean',
            $status === 'approved' => 'Approved with follow-up risk',
            $status === 'ready_for_review' => 'Ready for review',
            $status === 'intake_in_progress' && $confidenceSummary['weak_count'] === 0 && $unresolved === [] => 'Ready for review',
            $confidenceSummary['weak_count'] > 0 => 'Needs stronger answers',
            $unresolved !== [] => 'Missing required fields',
            default => 'In progress',
        };

        $note = match (true) {
            $confidenceSummary['weak_count'] > 0 => sprintf(
                '%d low-confidence field%s still need follow-up before this package is truly clean.',
                $confidenceSummary['weak_count'],
                $confidenceSummary['weak_count'] === 1 ? '' : 's',
            ),
            $unresolved !== [] => sprintf(
                '%d unresolved field%s are still open in the intake pipeline.',
                count($unresolved),
                count($unresolved) === 1 ? '' : 's',
            ),
            $status === 'intake_in_progress' && $confidenceSummary['weak_count'] === 0 && $unresolved === [] => 'Intake is clean enough to move into staff review whenever you are ready.',
            $status === 'approved' => 'Submission is approved and there are no unresolved intake or confidence blockers.',
            $status === 'exported' => 'Package artifacts have been exported from the current structured state.',
            $status === 'submitted' => 'This submission has been marked as submitted.',
            default => 'Continue intake, editing, or review to move the package forward.',
        };

        return [
            'label' => $label,
            'status' => $status,
            'current_step' => $currentStep,
            'unresolved_count' => count($unresolved),
            'weak_count' => $confidenceSummary['weak_count'],
            'note' => $note,
        ];
    }

    /**
     * @param array{label:string,status:string,current_step:string,unresolved_count:int,weak_count:int,note:string} $summary
     */
    private function renderReadinessPanel(array $summary): string
    {
        return sprintf(
            '<div class="readiness-grid"><div class="readiness-card"><strong>Readiness</strong>%s</div><div class="readiness-card"><strong>Status</strong>%s</div><div class="readiness-card"><strong>Current Step</strong>%s</div><div class="readiness-card"><strong>Risk Counts</strong>%d unresolved · %d low-confidence</div></div><div class="readiness-note">%s</div>',
            htmlspecialchars($summary['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($summary['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($summary['current_step'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $summary['unresolved_count'],
            $summary['weak_count'],
            htmlspecialchars($summary['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param array{label:string,status:string,current_step:string,unresolved_count:int,weak_count:int,note:string} $summary
     */
    private function renderReadinessAction(array $summary, string $submissionId, string $uri): string
    {
        $notice = match (true) {
            str_contains($uri, 'status=ready-for-review') => '<div class="notice">Submission moved to ready_for_review.</div>',
            str_contains($uri, 'error=not-ready') => '<div class="notice">Submission is not clean enough for review yet.</div>',
            default => '',
        };

        if ($summary['label'] !== 'Ready for review' || $summary['status'] !== 'intake_in_progress') {
            return $notice === '' ? '' : '<div class="readiness-action">' . $notice . '</div>';
        }

        return sprintf(
            '<div class="readiness-action"><form method="post" action="/submissions/%s/ready-for-review"><button type="submit">Mark Ready for Review</button></form>%s</div>',
            htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $notice,
        );
    }

    /**
     * @param list<array<string,mixed>> $researchLog
     * @param array<string,mixed> $validationState
     */
    private function renderResearchPanel(array $researchLog, array $validationState, string $submissionId): string
    {
        $items = [];
        $draftPanel = $this->renderResearchDraftsPanel(
            is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [],
            $submissionId,
        );

        foreach (array_reverse($researchLog, true) as $index => $item) {
            if (!is_array($item) || !isset($item['query'])) {
                continue;
            }

            $kind = htmlspecialchars((string) ($item['kind'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $query = htmlspecialchars((string) ($item['query'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $provider = htmlspecialchars((string) ($item['provider'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = htmlspecialchars((string) ($item['summary'] ?? 'No summary available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $citations = is_array($item['citations'] ?? null) ? $item['citations'] : [];
            $assessment = $this->assessResearchItem($item);

            $citationHtml = '';
            if ($citations !== []) {
                $links = [];
                foreach (array_slice($citations, 0, 3) as $citation) {
                    if (!is_array($citation)) {
                        continue;
                    }

                    $title = htmlspecialchars((string) ($citation['title'] ?? 'Source'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $url = htmlspecialchars((string) ($citation['url'] ?? '#'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    if (str_starts_with((string) ($citation['url'] ?? ''), '/')) {
                        $links[] = sprintf('<div><strong>%s</strong><div><code>%s</code></div></div>', $title, $url);
                    } else {
                        $links[] = sprintf('<div><a href="%s" target="_blank" rel="noreferrer">%s</a></div>', $url, $title);
                    }
                }

                if ($links !== []) {
                    $citationHtml = '<div class="annotation-value">' . implode('', $links) . '</div>';
                }
            }

            $researchMeta = sprintf(
                '<span>provider: %s</span><span>quality: %s</span>',
                $provider,
                htmlspecialchars((string) $assessment['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

            $draftActions = '';
            if ($assessment['draftable']) {
                $draftActions = sprintf(
                    '<form method="post" action="/submissions/%s/research/draft" style="margin-top: 12px; display: grid; gap: 10px;"><input type="hidden" name="research_index" value="%d"><div><label for="target_path_%d" style="display:block; margin-bottom:6px; font-size:0.82rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#6f665c;">Create Draft For</label><select id="target_path_%d" name="target_path" style="width:100%%; padding:10px 12px; border:1px solid #dbccb8; border-radius:10px; background:#fff; color:#171411;">%s</select></div><div><button type="submit" style="padding:10px 14px; border-radius:10px; border:1px solid #dbccb8; background:#315845; color:#fff; cursor:pointer;">Create Draft Proposal</button></div></form>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    (int) $index,
                    (int) $index,
                    (int) $index,
                    $this->renderResearchDraftTargetOptions((string) ($item['kind'] ?? 'research')),
                );
            } elseif ($assessment['reason'] !== '') {
                $draftActions = sprintf(
                    '<div class="annotation-value" style="margin-top:12px;"><strong>Draft locked</strong><div>%s</div></div>',
                    htmlspecialchars((string) $assessment['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }

            $items[] = sprintf(
                '<div class="annotation"><div class="annotation-path">%s</div><div class="annotation-meta">%s</div><div>%s</div>%s%s</div>',
                $kind . ($query !== '' ? ' · ' . $query : ''),
                $researchMeta,
                $summary,
                $citationHtml,
                $draftActions,
            );

            if (count($items) >= 4) {
                break;
            }
        }

        if ($items === []) {
            return $draftPanel . '<div class="annotation-empty">No recent research artifacts are attached to this submission.</div>';
        }

        return $draftPanel . '<div class="annotation-list">' . implode('', $items) . '</div>';
    }

    /**
     * @param list<array<string,mixed>> $drafts
     */
    private function renderResearchDraftsPanel(array $drafts, string $submissionId): string
    {
        $items = [];

        foreach (array_reverse($drafts) as $draft) {
            if (!is_array($draft)) {
                continue;
            }

            $status = (string) ($draft['status'] ?? 'pending');
            $targetPath = htmlspecialchars((string) ($draft['target_path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $source = htmlspecialchars((string) ($draft['source_query'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $value = htmlspecialchars((string) ($draft['suggested_value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $draftId = htmlspecialchars((string) ($draft['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $quality = htmlspecialchars((string) ($draft['draft_quality'] ?? 'unrated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $actions = '';
            if ($status === 'pending') {
                $actions = sprintf(
                    '<div class="annotation-meta"><form method="post" action="/submissions/%s/research/drafts/%s/apply"><button type="submit" style="padding:8px 12px; border-radius:10px; border:1px solid #dbccb8; background:#315845; color:#fff; cursor:pointer;">Apply Draft</button></form><form method="post" action="/submissions/%s/research/drafts/%s/reject"><button type="submit" style="padding:8px 12px; border-radius:10px; border:1px solid #dbccb8; background:#ba5a2d; color:#fff; cursor:pointer;">Reject Draft</button></form><a href="/submissions/%s?edit_path=%s">Inspect target field</a></div>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $draftId,
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $draftId,
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    rawurlencode((string) ($draft['target_path'] ?? '')),
                );
            } elseif ($status === 'applied' && array_key_exists('previous_value', $draft)) {
                $actions = sprintf(
                    '<div class="annotation-meta"><form method="post" action="/submissions/%s/research/drafts/%s/restore"><button type="submit" style="padding:8px 12px; border-radius:10px; border:1px solid #dbccb8; background:#83613d; color:#fff; cursor:pointer;">Restore Previous Value</button></form><a href="/submissions/%s?edit_path=%s">Inspect target field</a></div>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $draftId,
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    rawurlencode((string) ($draft['target_path'] ?? '')),
                );
            }

            $items[] = sprintf(
                '<div class="annotation"><div class="annotation-path">%s · %s</div><div class="annotation-meta"><span>status: %s</span><span>quality: %s</span><span>created: %s</span></div><div><strong>Source</strong><div>%s</div></div><div class="annotation-value">%s</div>%s</div>',
                $targetPath !== '' ? $targetPath : 'draft',
                htmlspecialchars((string) ($draft['source_kind'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $quality,
                htmlspecialchars((string) ($draft['created_at'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $source !== '' ? $source : 'n/a',
                $value !== '' ? $value : 'No suggested value available.',
                $actions,
            );
        }

        $notice = match (true) {
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'updated=research-draft') => '<div class="edit-notice">Research draft created.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'updated=research-draft-existing') => '<div class="edit-notice">An active draft already exists for this research item and target field.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'updated=research-applied') => '<div class="edit-notice">Research draft applied to canonical data.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'updated=research-rejected') => '<div class="edit-notice">Research draft rejected.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'updated=research-restored') => '<div class="edit-notice">Previous canonical value restored from research draft history.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'error=research-draft') => '<div class="edit-notice">Unable to create research draft.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'error=research-draft-ungrounded') => '<div class="edit-notice">Only grounded local-corpus research with citations can become a draft.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'error=research-apply') => '<div class="edit-notice">Unable to apply this draft. The source evidence no longer passes draft checks.</div>',
            str_contains($_SERVER['REQUEST_URI'] ?? '', 'error=research-restore') => '<div class="edit-notice">Unable to restore the previous value for this draft.</div>',
            default => '',
        };

        if ($items === []) {
            return $notice . '<div class="annotation-empty">No pending research drafts have been created yet.</div>';
        }

        return $notice . '<div class="annotation-list">' . implode('', $items) . '</div>';
    }

    /**
     * @param array<string,mixed> $validationState
     */
    private function renderAppliedResearchDraftsPanel(array $validationState, string $submissionId): string
    {
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        $items = [];

        foreach (array_reverse($drafts) as $draft) {
            if (!is_array($draft) || (string) ($draft['status'] ?? '') !== 'applied') {
                continue;
            }

            $targetPath = htmlspecialchars((string) ($draft['target_path'] ?? 'draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $provider = htmlspecialchars((string) ($draft['source_provider'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $source = htmlspecialchars((string) ($draft['source_query'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $quality = htmlspecialchars((string) ($draft['draft_quality'] ?? 'unrated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $appliedAt = htmlspecialchars((string) ($draft['applied_at'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $value = htmlspecialchars((string) ($draft['suggested_value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $items[] = sprintf(
                '<article class="annotation"><div class="annotation-path">%s</div><div class="annotation-meta"><span>provider: %s</span><span>quality: %s</span><span>applied: %s</span><span><a href="/submissions/%s?edit_path=%s">Inspect field</a></span></div><div><strong>Research source</strong><div>%s</div></div><div class="annotation-value">%s</div></article>',
                $targetPath,
                $provider,
                $quality,
                $appliedAt,
                htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                rawurlencode((string) ($draft['target_path'] ?? '')),
                $source !== '' ? $source : 'n/a',
                $value !== '' ? $value : 'No applied value stored.',
            );
        }

        if ($items === []) {
            return '<div class="annotation-empty">No active research-backed field updates are currently applied.</div>';
        }

        return '<div class="annotation-list">' . implode('', $items) . '</div>';
    }

    private function renderResearchDraftTargetOptions(string $kind): string
    {
        $preferred = match ($kind) {
            'costing' => 'funding_request.support_rationale',
            'market_validation' => 'business.market.marketing_plan',
            default => 'career_plan.three_year_plan',
        };

        $options = $this->researchDraftTargetOptions();

        $html = '';
        foreach ($options as $value => $label) {
            $selected = $value === $preferred ? ' selected' : '';
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $selected,
                htmlspecialchars($label . ' · ' . $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $researchItem
     */
    private function buildResearchDraftValue(array $researchItem, string $targetPath): string
    {
        $citations = is_array($researchItem['citations'] ?? null) ? $researchItem['citations'] : [];
        $titles = array_values(array_filter(array_map(
            static fn (mixed $citation): string => is_array($citation) ? trim((string) ($citation['title'] ?? '')) : '',
            $citations,
        )));
        $sourceList = $titles === [] ? 'local research evidence' : implode('; ', array_slice($titles, 0, 3));
        $snippet = $this->bestResearchSnippet($citations);
        if ($snippet === '') {
            $snippet = trim((string) ($researchItem['summary'] ?? ''));
        }

        $snippet = trim(preg_replace('/\s+/', ' ', $snippet) ?? $snippet);
        $snippet = rtrim($snippet, " \t\n\r\0\x0B.;:");
        if ($snippet === '') {
            $snippet = 'Grounded local evidence was captured in the NorthOps corpus.';
        }

        $evidence = 'Grounded local evidence points to ' . lcfirst($snippet) . '.';

        return match ($targetPath) {
            'funding_request.support_rationale' => 'Support is justified because the local NorthOps corpus shows this proposal still needs documented market grounding, startup readiness, and a clearer path from planning into launch. ' . $evidence . ' Sources: ' . $sourceList . '.',
            'business.market.marketing_plan' => 'Marketing should focus on grounded local outreach channels, relationship-based visibility, and proposal-ready messaging that fits the applicant\'s community context. ' . $evidence . ' Sources: ' . $sourceList . '.',
            default => 'The three-year plan should move from startup readiness into validated delivery, recurring work, and stronger local positioning. ' . $evidence . ' Sources: ' . $sourceList . '.',
        };
    }

    /**
     * @param list<array<string,mixed>> $citations
     */
    private function bestResearchSnippet(array $citations): string
    {
        $bestSnippet = '';
        $bestScore = PHP_INT_MIN;

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }

            $snippet = trim((string) ($citation['snippet'] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $score = 0;
            $title = strtolower(trim((string) ($citation['title'] ?? '')));
            $lower = strtolower($snippet);

            if (str_ends_with($title, '.md')) {
                $score += 8;
            }
            if (str_contains($title, 'cohort') || str_contains($title, 'proposal')) {
                $score += 4;
            }
            if (str_contains($lower, 'print / save as pdf') || str_contains($lower, ':root {')) {
                $score -= 10;
            }
            if (str_contains($lower, 'idea-to-proposal') || str_contains($lower, '8-week program') || str_contains($lower, 'participants')) {
                $score += 6;
            }
            $score += min(strlen($snippet), 220) / 40;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSnippet = $snippet;
            }
        }

        return $bestSnippet;
    }

    /**
     * @param array<string,mixed> $researchItem
     * @return array{draftable:bool,label:string,reason:string}
     */
    private function assessResearchItem(array $researchItem): array
    {
        $provider = trim((string) ($researchItem['provider'] ?? ''));
        $status = trim((string) ($researchItem['status'] ?? ''));
        $citations = is_array($researchItem['citations'] ?? null) ? $researchItem['citations'] : [];
        $hasCitations = count(array_filter($citations, static fn (mixed $citation): bool => is_array($citation) && trim((string) ($citation['title'] ?? '')) !== '')) > 0;

        if ($provider !== 'local_corpus') {
            return [
                'draftable' => false,
                'label' => 'web fallback only',
                'reason' => 'Only grounded local-corpus research can be promoted into a canonical draft.',
            ];
        }

        if ($status !== 'ok') {
            return [
                'draftable' => false,
                'label' => 'ungrounded',
                'reason' => 'This research item did not return an OK result.',
            ];
        }

        if (!$hasCitations) {
            return [
                'draftable' => false,
                'label' => 'uncited',
                'reason' => 'This research item has no usable citations yet.',
            ];
        }

        return [
            'draftable' => true,
            'label' => 'grounded local evidence',
            'reason' => '',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function researchDraftTargetOptions(): array
    {
        return [
            'funding_request.support_rationale' => 'Support Rationale',
            'business.market.marketing_plan' => 'Marketing Plan',
            'career_plan.three_year_plan' => 'Three-Year Plan',
        ];
    }

    private function getValueAtPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $cursor = $data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function valueToDraftString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $completionState
     */
    private function renderAppendixChecklist(array $completionState): string
    {
        $appendices = is_array($completionState['appendices'] ?? null) ? $completionState['appendices'] : [];
        $labels = [
            'A' => 'Appendix A',
            'B' => 'Appendix B',
            'F' => 'Appendix F',
            'G' => 'Appendix G',
            'H' => 'Appendix H',
            'M' => 'Appendix M',
        ];

        $items = [];
        foreach ($labels as $key => $label) {
            $complete = (bool) ($appendices[$key] ?? false);
            $items[] = sprintf(
                '<div class="checklist-item"><strong>%s</strong>%s</div>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $complete ? 'Complete' : 'Needs work',
            );
        }

        return '<div class="checklist">' . implode('', $items) . '</div>';
    }

    /**
     * @param array<string, array{comment:string,created_at:string,title:string}> $appendixNotes
     */
    private function renderAppendixNotesPanel(array $appendixNotes, array $appendixNoteActivity): string
    {
        $labels = [
            'A' => 'Appendix A',
            'B' => 'Appendix B',
            'F' => 'Appendix F',
            'G' => 'Appendix G',
            'H' => 'Appendix H',
            'M' => 'Appendix M',
        ];

        $items = [];
        foreach ($labels as $appendix => $label) {
            $note = $appendixNotes[$appendix] ?? [
                'comment' => '',
                'created_at' => '',
                'title' => '',
            ];
            $activity = $appendixNoteActivity[$appendix] ?? [
                'action_type' => '',
                'created_at' => '',
                'title' => '',
                'comment' => '',
            ];

            $body = trim((string) $note['comment']) !== ''
                ? nl2br(htmlspecialchars((string) $note['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                : 'No appendix-specific review note recorded.';
            $meta = $this->renderAppendixNoteActivityMeta($note, $activity);

            $items[] = sprintf(
                '<div class="annotation"><div class="annotation-path">%s</div><div>%s</div><div class="annotation-meta"><span>%s</span></div></div>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $body,
                $meta,
            );
        }

        return '<div class="annotation-list">' . implode('', $items) . '</div>';
    }

    /**
     * @param array{comment:string,created_at:string,title:string} $note
     * @param array{action_type:string,created_at:string,title:string,comment:string} $activity
     */
    private function renderAppendixNoteActivityMeta(array $note, array $activity): string
    {
        if (trim((string) ($note['created_at'] ?? '')) !== '') {
            return htmlspecialchars('Latest note saved at ' . (string) $note['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (($activity['action_type'] ?? '') === 'appendix_note_cleared' && trim((string) ($activity['created_at'] ?? '')) !== '') {
            return htmlspecialchars('Latest note activity: cleared at ' . (string) $activity['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return 'No note activity yet';
    }

    /**
     * @param list<array{section_key:string,field_path:string,comment:string,action_type:string,created_at:string,title:string}> $annotations
     * @param array<string, mixed> $canonicalData
     */
    private function renderFieldReviewPanel(array $annotations, array $canonicalData, string $submissionId): string
    {
        if ($annotations === []) {
            return sprintf(
                '<div class="annotation-empty">No field-specific review notes are attached yet. <a href="/submissions/%s/review">Open the review cockpit</a> to add one.</div>',
                htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        $items = '';

        foreach ($annotations as $annotation) {
            $value = $this->valueAtPath($canonicalData, $annotation['field_path']);
            $renderedValue = $this->renderAnnotationValue($value);
            $reviewUrl = sprintf(
                '/submissions/%s/review?section_key=%s&field_path=%s',
                rawurlencode($submissionId),
                rawurlencode($annotation['section_key']),
                rawurlencode($annotation['field_path']),
            );
            $editUrl = sprintf(
                '/submissions/%s?edit_path=%s',
                rawurlencode($submissionId),
                rawurlencode($annotation['field_path']),
            );

            $items .= sprintf(
                '<article class="annotation"><div class="annotation-path">%s</div><div class="annotation-meta"><span>Section: %s</span><span>Type: %s</span><span>Updated: %s</span><span><a href="%s">Reply in review cockpit</a></span><span><a href="%s">Edit current value</a></span></div><div>%s</div><div class="annotation-value">%s</div></article>',
                htmlspecialchars($annotation['field_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($annotation['section_key'] !== '' ? $annotation['section_key'] : 'n/a', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($annotation['action_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($annotation['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($reviewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($editUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($annotation['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($renderedValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return '<div class="annotation-list">' . $items . '</div>';
    }

    /**
     * @param array<string, mixed> $canonicalData
     */
    private function renderCanonicalEditPanel(
        array $canonicalData,
        string $submissionId,
        string $requestedPath,
        string $requestedFormat,
        string $notice,
    ): string {
        $fieldPath = trim($requestedPath);
        $currentValue = $fieldPath !== '' ? $this->valueAtPath($canonicalData, $fieldPath) : null;
        $renderedValue = $fieldPath !== '' ? $this->renderAnnotationValue($currentValue) : '';
        $selectedString = $requestedFormat === 'string' ? ' selected' : '';
        $selectedJson = $requestedFormat === 'json' ? ' selected' : '';
        $selectedBoolean = $requestedFormat === 'boolean' ? ' selected' : '';
        $selectedInteger = $requestedFormat === 'integer' ? ' selected' : '';

        return sprintf(
            '<div class="edit-layout">%s<form method="post" action="/submissions/%s/canonical"><div><label for="field_path">Field Path</label><input id="field_path" name="field_path" value="%s" placeholder="business.operations.launch_timeline"></div><div><label for="value_format">Value Format</label><select id="value_format" name="value_format"><option value="string"%s>string</option><option value="json"%s>json</option><option value="boolean"%s>boolean</option><option value="integer"%s>integer</option></select></div><div><label for="field_value">Field Value</label><textarea id="field_value" name="field_value" placeholder="New canonical value">%s</textarea></div><div><button type="submit">Update Canonical Value</button></div></form><div class="edit-note"><strong>Current Resolved Value</strong><p>Use dot notation to patch canonical state directly. JSON is useful for arrays or objects; string is the default for single-value fields.</p><div class="annotation-value">%s</div></div></div>',
            $notice,
            htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($fieldPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $selectedString,
            $selectedJson,
            $selectedBoolean,
            $selectedInteger,
            htmlspecialchars($fieldPath !== '' ? $renderedValue : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($fieldPath !== '' ? $renderedValue : 'Choose a canonical path to inspect or update.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param array<string, mixed> $canonicalData
     */
    private function valueAtPath(array $canonicalData, string $path): mixed
    {
        $current = $canonicalData;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function renderAnnotationValue(mixed $value): string
    {
        if ($value === null) {
            return 'No value currently mapped for this canonical path.';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return $string !== '' ? $string : '[empty string]';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[unrenderable value]' : $json;
    }

    private function editNoticeFromUri(string $uri): string
    {
        return match (true) {
            str_contains($uri, 'updated=canonical') => '<div class="edit-notice">Canonical field updated.</div>',
            str_contains($uri, 'error=canonical') => '<div class="edit-notice">Unable to update canonical data. Check the field path and value format.</div>',
            default => '',
        };
    }

    private function parseCanonicalValue(string $rawValue, string $valueFormat): mixed
    {
        return match ($valueFormat) {
            'json' => $this->decodeJsonValue($rawValue),
            'boolean' => match (strtolower(trim($rawValue))) {
                '1', 'true', 'yes' => true,
                '0', 'false', 'no' => false,
                default => throw new \InvalidArgumentException('Invalid boolean value.'),
            },
            'integer' => filter_var($rawValue, FILTER_VALIDATE_INT) !== false
                ? (int) $rawValue
                : throw new \InvalidArgumentException('Invalid integer value.'),
            default => $rawValue,
        };
    }

    private function decodeJsonValue(string $rawValue): mixed
    {
        $decoded = json_decode($rawValue, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON value.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $canonicalData
     */
    private function setValueAtPath(array &$canonicalData, string $path, mixed $value): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            throw new \InvalidArgumentException('Canonical path cannot be empty.');
        }

        $current =& $canonicalData;

        foreach ($segments as $index => $segment) {
            $isLast = $index === array_key_last($segments);

            if ($isLast) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current =& $current[$segment];
        }
    }
}
