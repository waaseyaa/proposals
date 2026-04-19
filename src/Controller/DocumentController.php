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
use Twig\Environment;
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
        private readonly Environment $twig,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $documents = $this->documentPreviewService->buildAndPersist($submission);
        $sections = [];
        foreach ($documents as $document) {
            $sections[] = (string) ($document['html'] ?? '');
        }

        $html = $this->twig->render('pages/documents/show.html.twig', [
            'title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => (string) $submission->id(),
            'page_styles' => $this->documentPreviewService->pageStyles(),
            'sections' => $sections,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function package(int|string $submissionId): Response
    {
        $submission = $this->entityTypeManager->getStorage('proposal_submission')->load($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $package = $this->documentPreviewService->buildPackageAndPersist($submission);

        $html = $this->twig->render('pages/documents/package.html.twig', [
            'title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => (string) $submission->id(),
            'page_styles' => $this->documentPreviewService->pageStyles(),
            'package_html' => (string) ($package['html'] ?? ''),
        ]);

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
        $readinessSummary = $this->buildReadinessSummary($submission);

        $rows = [];
        foreach ($documents as $document) {
            $rows[] = $this->buildExportRowView($document, (string) $submission->id());
        }

        $html = $this->twig->render('pages/documents/exports.html.twig', [
            'title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => (string) $submission->id(),
            'notice_regenerated' => str_contains($_SERVER['REQUEST_URI'] ?? '', 'regenerated=pdf'),
            'review' => [
                'status' => (string) $reviewSummary['status'],
                'current_step' => (string) $reviewSummary['current_step'],
                'review_count' => (string) $reviewSummary['review_count'],
                'reviewed_appendices' => (string) $reviewSummary['reviewed_appendix_count'] . '/' . (string) $reviewSummary['reviewed_appendix_total'],
                'latest_created_at' => (string) ($reviewSummary['latest_created_at'] ?: 'n/a'),
                'latest_comment' => (string) ($reviewSummary['latest_comment'] ?: 'No staff review notes recorded yet.'),
            ],
            'readiness' => $readinessSummary,
            'artifact_audit' => $this->buildArtifactAuditView($artifactAudit),
            'completion_state' => is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [],
            'appendix_notes' => $this->buildAppendixNotesView($appendixNotes, $appendixNoteActivity),
            'research_backed' => $this->buildResearchBackedView(
                is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
                (string) $submission->id(),
            ),
            'weak_fields' => $this->buildWeakFieldsView(
                is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
            ),
            'rows' => $rows,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildExportRowView(object $document, string $submissionId): array
    {
        $documentType = (string) ($document->get('document_type') ?? '');
        $version = (string) ($document->get('version') ?? '');
        $generated = (string) ($document->get('generated_at') ?? '');
        $storagePath = (string) ($document->get('storage_path') ?? '');
        $metadata = $document->get('metadata');
        $size = 'n/a';

        if (is_array($metadata) && isset($metadata['size']) && is_numeric($metadata['size'])) {
            $size = $this->formatBytes((int) $metadata['size']);
        } elseif ($storagePath !== '' && is_file($storagePath)) {
            $size = $this->formatBytes(filesize($storagePath) ?: 0);
        }

        return [
            'label' => (string) ($document->label() ?? $documentType),
            'document_type' => $documentType,
            'ready' => $storagePath !== '',
            'version' => $version,
            'generated' => $generated,
            'size' => $size,
            'actions' => $this->buildActionsView($documentType, $submissionId),
        ];
    }

    /**
     * @return list<array{href:string,label:string}>
     */
    private function buildActionsView(string $documentType, string $submissionId): array
    {
        $base = '/submissions/' . rawurlencode($submissionId);
        return match ($documentType) {
            'merged_package_pdf' => [
                ['href' => $base . '/package/pdf', 'label' => 'Open'],
                ['href' => $base . '/package/pdf/download', 'label' => 'Download'],
                ['href' => $base . '/exports/pdf/regenerate', 'label' => 'Regenerate'],
            ],
            'artifact_bundle_zip' => [
                ['href' => $base . '/exports/bundle/download', 'label' => 'Download bundle'],
            ],
            'merged_package_preview' => [
                ['href' => $base . '/package', 'label' => 'Open package'],
                ['href' => $base . '/exports/file/' . rawurlencode($documentType), 'label' => 'Open HTML'],
                ['href' => $base . '/exports/file/' . rawurlencode($documentType) . '/download', 'label' => 'Download'],
            ],
            default => [
                ['href' => $base . '/documents', 'label' => 'Open appendices'],
                ['href' => $base . '/exports/file/' . rawurlencode($documentType), 'label' => 'Open HTML'],
                ['href' => $base . '/exports/file/' . rawurlencode($documentType) . '/download', 'label' => 'Download'],
            ],
        };
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
     * @return list<string>
     */
    private function buildWeakFieldsView(array $confidenceState): array
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

        return $weak;
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
     * @param array<string,mixed> $validationState
     * @return list<array<string,string>>
     */
    private function buildResearchBackedView(array $validationState, string $submissionId): array
    {
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        $items = [];

        foreach (array_reverse($drafts) as $draft) {
            if (!is_array($draft) || (string) ($draft['status'] ?? '') !== 'applied') {
                continue;
            }

            $items[] = [
                'target_path' => (string) ($draft['target_path'] ?? 'draft'),
                'source_query' => (string) ($draft['source_query'] ?? 'n/a'),
                'source_provider' => (string) ($draft['source_provider'] ?? 'research'),
                'draft_quality' => (string) ($draft['draft_quality'] ?? 'unrated'),
                'applied_at' => (string) ($draft['applied_at'] ?? 'n/a'),
                'submission_id' => $submissionId,
                'target_path_encoded' => rawurlencode((string) ($draft['target_path'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * @param array{ready_count:int,total_count:int,missing:list<string>,items:list<array{document_type:string,label:string,ready:bool}>} $artifactAudit
     * @return array{ready_count:int,total_count:int,missing_count:int,missing:list<string>}
     */
    private function buildArtifactAuditView(array $artifactAudit): array
    {
        return [
            'ready_count' => (int) $artifactAudit['ready_count'],
            'total_count' => (int) $artifactAudit['total_count'],
            'missing_count' => count($artifactAudit['missing']),
            'missing' => $artifactAudit['missing'],
        ];
    }

    /**
     * @param array<string, array{comment:string,created_at:string,title:string}> $appendixNotes
     * @param array<string, array{action_type:string,created_at:string,title:string,comment:string}> $appendixNoteActivity
     * @return list<array{label:string,body:string,body_is_raw:bool,meta:string}>
     */
    private function buildAppendixNotesView(array $appendixNotes, array $appendixNoteActivity): array
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

            $comment = trim((string) $note['comment']);
            $body = $comment !== ''
                ? nl2br(htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                : 'No appendix-specific review note recorded.';

            $items[] = [
                'label' => $label,
                'body' => $body,
                'body_is_raw' => $comment !== '', // nl2br output must pass through raw
                'meta' => $this->appendixNoteActivityMeta($note, $activity),
            ];
        }

        return $items;
    }

    /**
     * @param array{comment:string,created_at:string,title:string} $note
     * @param array{action_type:string,created_at:string,title:string,comment:string} $activity
     */
    private function appendixNoteActivityMeta(array $note, array $activity): string
    {
        if (trim((string) ($note['created_at'] ?? '')) !== '') {
            return 'Latest note saved at ' . (string) $note['created_at'];
        }

        if (($activity['action_type'] ?? '') === 'appendix_note_cleared' && trim((string) ($activity['created_at'] ?? '')) !== '') {
            return 'Latest note activity: cleared at ' . (string) $activity['created_at'];
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
