<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Generation\ArtifactBundleService;
use App\Domain\Generation\DocumentPreviewService;
use App\Domain\Generation\PdfGenerationService;
use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class DocumentController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly PdfGenerationService $pdfGenerationService,
        private readonly ArtifactBundleService $artifactBundleService,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $documents = $this->documentPreviewService->buildAndPersist($submission);
        $sections = '';
        foreach ($documents as $document) {
            $sections .= $document['html'];
        }

        $title = htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $styles = $this->documentPreviewService->pageStyles();

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Document Previews · Miikana</title>
  <style>__STYLES__</style>
</head>
<body>
  <main>
    <div class="hero">
      <div class="eyebrow">Appendix Documents</div>
      <h1>__TITLE__</h1>
      <p>These appendix panels follow the real ISET package sequence and print framing from the NorthOps HTML prototype, but they render from stored submission state inside Waaseyaa.</p>
      <div class="nav">
        <a href="/submissions">Back to submissions</a>
        <a href="/submissions/__ID__">Structured data</a>
        <a href="/submissions/__ID__/package">Merged package</a>
        <a href="/submissions/__ID__/package/pdf">Generate PDF</a>
      </div>
    </div>
    <div class="stack">__DOCUMENTS__</div>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__ID__', '__DOCUMENTS__', '__STYLES__'],
            [$title, htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $sections, $styles],
            $html,
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function package(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $package = $this->documentPreviewService->buildPackageAndPersist($submission);
        $title = htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $styles = $this->documentPreviewService->pageStyles();

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Merged Package · Miikana</title>
  <style>__STYLES__</style>
</head>
<body>
  <main>
    <div class="hero">
      <div class="eyebrow">Merged Package</div>
      <h1>__TITLE__</h1>
      <p>This is the printable package bundle: cover sheet plus Appendices A, B, F, G, H, and M in submission order.</p>
      <div class="nav">
        <a href="/submissions">Back to submissions</a>
        <a href="/submissions/__ID__/documents">Appendix documents</a>
        <a href="#" onclick="window.print(); return false;">Print / Save PDF</a>
        <a href="/submissions/__ID__/package/pdf">Generate PDF</a>
      </div>
    </div>
    <div class="stack">__PACKAGE__</div>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__ID__', '__PACKAGE__', '__STYLES__'],
            [$title, htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $package['html'], $styles],
            $html,
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function pdf(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $result = $this->pdfGenerationService->generatePackagePdf($submission);
        $response = new BinaryFileResponse($result['path']);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $result['filename']);

        return $response;
    }

    public function downloadPdf(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $result = $this->pdfGenerationService->generatePackagePdf($submission);
        $response = new BinaryFileResponse($result['path']);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result['filename']);

        return $response;
    }

    public function regeneratePdf(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $this->pdfGenerationService->generatePackagePdf($submission);

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/exports?regenerated=pdf');
    }

    public function downloadBundle(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $result = $this->artifactBundleService->buildAndPersist($submission, true);
        $response = new BinaryFileResponse($result['path']);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $result['filename']);

        return $response;
    }

    public function exportFile(int|string $submissionId, string $documentType): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $document = $this->findDocument($submissionId, $documentType);
        if ($document === null) {
            return new Response('Export artifact not found.', 404);
        }

        $path = (string) ($document->get('storage_path') ?? '');
        if ($path === '' || !is_file($path)) {
            return new Response('Artifact file missing.', 404);
        }

        $format = (string) ($document->get('format') ?? 'html');
        if ($format === 'html') {
            $contents = file_get_contents($path);
            if ($contents === false) {
                return new Response('Unable to read artifact.', 500);
            }

            return new Response($contents, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return new BinaryFileResponse($path);
    }

    public function downloadExportFile(int|string $submissionId, string $documentType): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $document = $this->findDocument($submissionId, $documentType);
        if ($document === null) {
            return new Response('Export artifact not found.', 404);
        }

        $path = (string) ($document->get('storage_path') ?? '');
        if ($path === '' || !is_file($path)) {
            return new Response('Artifact file missing.', 404);
        }

        $metadata = $document->get('metadata');
        $filename = is_array($metadata) && isset($metadata['filename']) ? (string) $metadata['filename'] : basename($path);
        $format = (string) ($document->get('format') ?? 'html');

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $format === 'html' ? 'text/html; charset=UTF-8' : 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    public function exports(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        // Keep export status current when the dashboard is opened.
        $this->documentPreviewService->buildAndPersist($submission);
        $this->documentPreviewService->buildPackageAndPersist($submission);
        $this->artifactBundleService->buildAndPersist($submission);

        $documentStorage = $this->entityTypeManager->getStorage('proposal_document');
        $documents = array_values(array_filter(
            $documentStorage->loadMultiple($documentStorage->getQuery()->execute()),
            static fn (object $document): bool => (int) ($document->get('submission_id') ?? 0) === (int) $submission->id(),
        ));
        $reviewSummary = $this->reviewService->summarizeSubmission($submission);
        $appendixNotes = $this->reviewService->latestAppendixNotes($submissionId);
        $appendixNoteActivity = $this->reviewService->latestAppendixNoteActivity($submissionId);
        $artifactAudit = $this->artifactAuditService->summarize($submission);
        $confidenceWarning = $this->renderConfidenceWarning(
            is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
        );
        $readinessSummary = $this->buildReadinessSummary($submission);
        $appendixChecklist = $this->renderAppendixChecklist(
            is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [],
        );
        $appendixNotesPanel = $this->renderAppendixNotesPanel($appendixNotes, $appendixNoteActivity);
        $researchBackedPanel = $this->renderAppliedResearchDraftsPanel(
            is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
            (string) $submission->id(),
        );
        $artifactAuditPanel = $this->renderArtifactAuditPanel($artifactAudit);

        $rows = '';
        foreach ($documents as $document) {
            $rows .= $this->renderExportRow($document, (string) $submission->id());
        }

        $notice = str_contains($_SERVER['REQUEST_URI'] ?? '', 'regenerated=pdf')
            ? '<div class="notice">PDF regenerated from the current merged package.</div>'
            : '';

        $title = htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exports · Miikana</title>
  <style>
    :root {
      --ink: #171411;
      --paper: #fffdfa;
      --sand: #f4ebde;
      --line: #dbcbb7;
      --moss: #315845;
      --rust: #ba5a2d;
      --muted: #6f665c;
      --card: #f7efe3;
      --success: #21663a;
    }
    * { box-sizing: border-box; }
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
    .hero, .card {
      background: rgba(255, 253, 250, 0.94);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 10px 30px rgba(76, 62, 44, 0.06);
    }
    .hero { margin-bottom: 24px; }
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
    .notice {
      margin: 16px 0 0;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(33, 102, 58, 0.18);
      background: rgba(33, 102, 58, 0.08);
      color: var(--success);
      font-weight: 700;
    }
    .review-card {
      margin-bottom: 24px;
    }
    .review-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    .review-metric {
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: #fffefd;
    }
    .review-metric strong {
      display: block;
      font-size: 0.75rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 6px;
    }
    .review-note {
      margin-top: 14px;
      border-left: 4px solid var(--rust);
      padding-left: 14px;
    }
    .readiness-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 14px;
      color: var(--muted);
    }
    .checklist {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }
    .checklist-item {
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px;
      background: #fffefd;
    }
    .checklist-item strong {
      display: block;
      margin-bottom: 6px;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
    }
    .note-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }
    .note-item {
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px;
      background: #fffefd;
    }
    .note-item strong {
      display: block;
      margin-bottom: 6px;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
    }
    .note-meta {
      margin-top: 8px;
      color: var(--muted);
      font-size: 0.86rem;
    }
    .warning {
      margin: 18px 0 0;
      padding: 14px 16px;
      border-radius: 14px;
      border: 1px solid rgba(186, 90, 45, 0.28);
      background: rgba(186, 90, 45, 0.08);
      color: var(--ink);
    }
    .warning strong {
      display: block;
      margin-bottom: 6px;
      color: var(--rust);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 6px;
    }
    th, td {
      padding: 12px 10px;
      border-top: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
    }
    th {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      background: var(--card);
    }
    .status {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(49, 88, 69, 0.08);
      color: var(--moss);
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .actions a {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: #fff;
      text-decoration: none;
      font-size: 0.88rem;
    }
    code {
      background: #f3ede5;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 2px 6px;
    }
    @media (max-width: 860px) {
      .checklist { grid-template-columns: 1fr 1fr; }
      .note-grid { grid-template-columns: 1fr; }
      .review-grid { grid-template-columns: 1fr 1fr; }
      table, thead, tbody, tr, th, td { display: block; }
      thead { display: none; }
      td { padding-left: 0; }
      tr + tr td:first-child { border-top: 1px solid var(--line); }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="eyebrow">Exports</div>
      <h1>__TITLE__</h1>
      <p>This surface shows the current export artifacts for the submission, including generated appendix HTML, merged package state, and the latest PDF output. Use it to open, download, or regenerate deliverables without hunting for direct URLs.</p>
      <div class="nav">
        <a href="/submissions">Back to submissions</a>
        <a href="/submissions/__ID__">Structured data</a>
        <a href="/submissions/__ID__/documents">Appendix documents</a>
        <a href="/submissions/__ID__/package">Merged package</a>
        <a href="/submissions/__ID__/package/pdf">Open PDF</a>
        <a href="/submissions/__ID__/exports/bundle/download">Download bundle</a>
      </div>
      __NOTICE__
    </section>
    <section class="card review-card">
      <div class="eyebrow">Review State</div>
      <p>The export path now reflects staff workflow state, so reviewers can see whether this package is still in revisions or already moving toward approval and submission.</p>
      <div class="review-grid">
        <div class="review-metric"><strong>Status</strong>__REVIEW_STATUS__</div>
        <div class="review-metric"><strong>Current Step</strong>__REVIEW_STEP__</div>
        <div class="review-metric"><strong>Review Actions</strong>__REVIEW_COUNT__</div>
        <div class="review-metric"><strong>Appendices Reviewed</strong>__REVIEWED_APPENDICES__</div>
        <div class="review-metric"><strong>Latest Activity</strong>__REVIEW_AT__</div>
      </div>
      <div class="readiness-strip"><span>Readiness: __READINESS_LABEL__</span><span>Unresolved: __READINESS_UNRESOLVED__</span><span>Low confidence: __READINESS_WEAK__</span></div>
      __ARTIFACT_AUDIT_PANEL__
      __APPENDIX_CHECKLIST__
      __APPENDIX_NOTES_PANEL__
      __RESEARCH_BACKED_PANEL__
      <div class="review-note"><strong>Latest Note</strong><div>__REVIEW_NOTE__</div></div>
      __CONFIDENCE_WARNING__
      <p><a href="/submissions/__ID__/review">Open full review cockpit</a></p>
    </section>
    <section class="card">
      <div class="eyebrow">Artifacts</div>
      <table>
        <thead>
          <tr>
            <th>Artifact</th>
            <th>Status</th>
            <th>Version</th>
            <th>Generated</th>
            <th>Size</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>__ROWS__</tbody>
      </table>
    </section>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__ID__', '__NOTICE__', '__ROWS__', '__REVIEW_STATUS__', '__REVIEW_STEP__', '__REVIEW_COUNT__', '__REVIEWED_APPENDICES__', '__REVIEW_AT__', '__REVIEW_NOTE__', '__CONFIDENCE_WARNING__', '__READINESS_LABEL__', '__READINESS_UNRESOLVED__', '__READINESS_WEAK__', '__ARTIFACT_AUDIT_PANEL__', '__APPENDIX_CHECKLIST__', '__APPENDIX_NOTES_PANEL__', '__RESEARCH_BACKED_PANEL__'],
            [
                $title,
                htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $notice,
                $rows,
                htmlspecialchars((string) $reviewSummary['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $reviewSummary['current_step'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $reviewSummary['review_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $reviewSummary['reviewed_appendix_count'] . '/' . (string) $reviewSummary['reviewed_appendix_total'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($reviewSummary['latest_created_at'] ?: 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) (($reviewSummary['latest_comment'] ?: 'No staff review notes recorded yet.')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $confidenceWarning,
                htmlspecialchars($readinessSummary['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $readinessSummary['unresolved_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $readinessSummary['weak_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $artifactAuditPanel,
                $appendixChecklist,
                $appendixNotesPanel,
                $researchBackedPanel,
            ],
            $html,
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function renderExportRow(object $document, string $submissionId): string
    {
        $documentType = (string) ($document->get('document_type') ?? '');
        $label = htmlspecialchars((string) ($document->label() ?? $documentType), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $version = htmlspecialchars((string) ($document->get('version') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $generated = htmlspecialchars((string) ($document->get('generated_at') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $storagePath = (string) ($document->get('storage_path') ?? '');
        $metadata = $document->get('metadata');
        $size = 'n/a';

        if (is_array($metadata) && isset($metadata['size']) && is_numeric($metadata['size'])) {
            $size = $this->formatBytes((int) $metadata['size']);
        } elseif ($storagePath !== '' && is_file($storagePath)) {
            $size = $this->formatBytes(filesize($storagePath) ?: 0);
        }

        $actions = $this->actionsFor($documentType, $submissionId);

        return sprintf(
            '<tr><td><strong>%s</strong><br><code>%s</code></td><td><span class="status">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td><div class="actions">%s</div></td></tr>',
            $label,
            htmlspecialchars($documentType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $storagePath !== '' ? 'ready' : 'pending',
            $version !== '' ? $version : '&nbsp;',
            $generated !== '' ? $generated : '&nbsp;',
            htmlspecialchars($size, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $actions,
        );
    }

    private function actionsFor(string $documentType, string $submissionId): string
    {
        return match ($documentType) {
            'merged_package_pdf' => implode('', [
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/package/pdf', 'Open'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/package/pdf/download', 'Download'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/pdf/regenerate', 'Regenerate'),
            ]),
            'artifact_bundle_zip' => implode('', [
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/bundle/download', 'Download bundle'),
            ]),
            'merged_package_preview' => implode('', [
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/package', 'Open package'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/file/' . rawurlencode($documentType), 'Open HTML'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/file/' . rawurlencode($documentType) . '/download', 'Download'),
            ]),
            default => implode('', [
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/documents', 'Open appendices'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/file/' . rawurlencode($documentType), 'Open HTML'),
                $this->actionLink('/submissions/' . rawurlencode($submissionId) . '/exports/file/' . rawurlencode($documentType) . '/download', 'Download'),
            ]),
        };
    }

    private function actionLink(string $href, string $label): string
    {
        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }

    /**
     * @param array<string, mixed> $confidenceState
     */
    private function renderConfidenceWarning(array $confidenceState): string
    {
        $weak = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence'])) {
                continue;
            }

            if (($state['resolved'] ?? true) === false) {
                $weak[] = sprintf(
                    '%s (%s)',
                    (string) $path,
                    (string) ($state['note'] ?? 'follow-up required'),
                );
            }
        }

        if ($weak === []) {
            return '';
        }

        return sprintf(
            '<div class="warning"><strong>Low-confidence fields are still present in this package.</strong><div>%s</div></div>',
            htmlspecialchars(implode(' · ', $weak), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @return array{label:string,unresolved_count:int,weak_count:int}
     */
    private function buildReadinessSummary(object $submission): array
    {
        $unresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $weakCount = 0;
        $confidenceState = is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [];

        foreach ($confidenceState as $state) {
            if (is_array($state) && isset($state['confidence']) && ($state['resolved'] ?? true) === false) {
                $weakCount++;
            }
        }

        $status = (string) ($submission->get('status') ?? 'draft');
        $label = match (true) {
            $weakCount > 0 => 'Needs stronger answers',
            $unresolved !== [] => 'Missing required fields',
            $status === 'ready_for_review' => 'Ready for review',
            $status === 'intake_in_progress' && $weakCount === 0 && $unresolved === [] => 'Ready for review',
            $status === 'approved' => 'Approved and clean',
            $status === 'exported' => 'Exported',
            $status === 'submitted' => 'Submitted',
            default => 'In progress',
        };

        return [
            'label' => $label,
            'unresolved_count' => count($unresolved),
            'weak_count' => $weakCount,
        ];
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
            $items[] = sprintf(
                '<div class="checklist-item"><strong>%s</strong>%s</div>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (bool) ($appendices[$key] ?? false) ? 'Complete' : 'Needs work',
            );
        }

        return '<div class="checklist">' . implode('', $items) . '</div>';
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

            $items[] = sprintf(
                '<div class="note-item"><strong>%s</strong><div>%s</div><div class="note-meta"><span>provider: %s</span> · <span>quality: %s</span> · <span>applied: %s</span> · <a href="/submissions/%s?edit_path=%s">Inspect field</a></div></div>',
                htmlspecialchars((string) ($draft['target_path'] ?? 'draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['source_query'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['source_provider'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['draft_quality'] ?? 'unrated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['applied_at'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                rawurlencode((string) ($draft['target_path'] ?? '')),
            );
        }

        if ($items === []) {
            return '<div class="warning"><strong>No active research-backed updates</strong><div>No currently applied field values were promoted from research drafts.</div></div>';
        }

        return '<div class="note-grid">' . implode('', $items) . '</div>';
    }

    /**
     * @param array{ready_count:int,total_count:int,missing:list<string>,items:list<array{document_type:string,label:string,ready:bool}>} $artifactAudit
     */
    private function renderArtifactAuditPanel(array $artifactAudit): string
    {
        $summary = sprintf(
            '<div class="readiness-strip"><span>Artifacts ready: %d/%d</span><span>Missing: %d</span></div>',
            (int) $artifactAudit['ready_count'],
            (int) $artifactAudit['total_count'],
            count($artifactAudit['missing']),
        );

        if ($artifactAudit['missing'] === []) {
            return $summary;
        }

        return $summary . sprintf(
            '<div class="warning"><strong>Artifact audit</strong><div>%s</div></div>',
            htmlspecialchars(implode(' · ', $artifactAudit['missing']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
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
                '<div class="note-item"><strong>%s</strong><div>%s</div><div class="note-meta">%s</div></div>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $body,
                $meta,
            );
        }

        return '<div class="note-grid">' . implode('', $items) . '</div>';
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

    private function findDocument(int|string $submissionId, string $documentType): ?object
    {
        $storage = $this->entityTypeManager->getStorage('proposal_document');
        foreach ($storage->loadMultiple($storage->getQuery()->execute()) as $document) {
            if ((int) ($document->get('submission_id') ?? 0) !== (int) $submissionId) {
                continue;
            }
            if ((string) ($document->get('document_type') ?? '') !== $documentType) {
                continue;
            }

            return $document;
        }

        return null;
    }
}
