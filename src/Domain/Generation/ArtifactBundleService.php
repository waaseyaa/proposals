<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class ArtifactBundleService
{
    public function __construct(
        private readonly EntityStorageInterface $documentStorage,
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly PdfGenerationService $pdfGenerationService,
        private readonly string $projectRoot,
    ) {}

    /**
     * @return array{path:string,filename:string,document_type:string}
     */
    public function buildAndPersist(EntityInterface $submission, bool $ensurePdf = false): array
    {
        $this->documentPreviewService->buildAndPersist($submission);
        $this->documentPreviewService->buildPackageAndPersist($submission);

        if ($ensurePdf) {
            $this->pdfGenerationService->generatePackagePdf($submission);
        }

        $documents = $this->loadSubmissionDocuments($submission->id());
        $outputDir = rtrim($this->projectRoot, '/') . '/storage/proposals/generated/' . $submission->id();
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $bundleFilename = $this->bundleFilename($submission);
        $bundlePath = $outputDir . '/' . $bundleFilename;

        $zip = new \ZipArchive();
        $result = $zip->open($bundlePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException(sprintf('Unable to create artifact bundle zip for submission "%s".', (string) $submission->id()));
        }

        $included = [];
        foreach ($documents as $document) {
            $documentType = (string) ($document->get('document_type') ?? '');
            if ($documentType === 'artifact_bundle_zip') {
                continue;
            }

            $path = (string) ($document->get('storage_path') ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $metadata = $document->get('metadata');
            $filename = is_array($metadata) && isset($metadata['filename']) && is_string($metadata['filename']) && $metadata['filename'] !== ''
                ? $metadata['filename']
                : basename($path);

            if ($zip->addFile($path, $filename)) {
                $included[] = $filename;
            }
        }

        $generatedAt = gmdate(DATE_ATOM);
        $status = (string) ($submission->get('status') ?? 'draft');
        $manifest = [
            'submission_id' => $submission->id(),
            'submission_label' => (string) ($submission->label() ?? ''),
            'status' => $status,
            'generated_at' => $generatedAt,
            'included_documents' => $included,
            'research_backed_updates' => $this->appliedResearchDrafts($submission),
        ];
        $zip->addFromString('bundle-manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $readmeLines = [
            'Miikana Artifact Bundle',
            'Submission: ' . (string) ($submission->label() ?? ''),
            'Status: ' . $status,
            'Generated: ' . $generatedAt,
            '',
            'Included files:',
        ];
        foreach ($included as $filename) {
            $readmeLines[] = '- ' . $filename;
        }
        if ($included === []) {
            $readmeLines[] = '- No export artifacts were available when this bundle was generated.';
        }
        $appliedResearchDrafts = $this->appliedResearchDrafts($submission);
        $readmeLines[] = '';
        $readmeLines[] = 'Research-backed field updates:';
        if ($appliedResearchDrafts === []) {
            $readmeLines[] = '- None active at bundle generation time.';
        } else {
            foreach ($appliedResearchDrafts as $draft) {
                $readmeLines[] = sprintf(
                    '- %s <- %s (%s)',
                    (string) ($draft['target_path'] ?? 'draft'),
                    (string) ($draft['source_query'] ?? 'research'),
                    (string) ($draft['source_provider'] ?? 'research'),
                );
            }
        }
        $zip->addFromString('README.txt', implode("\n", $readmeLines) . "\n");

        $zip->close();

        $this->persistBundleDocument($submission, $bundlePath, $bundleFilename, $included);

        return [
            'path' => $bundlePath,
            'filename' => $bundleFilename,
            'document_type' => 'artifact_bundle_zip',
        ];
    }

    /**
     * @return array<int, EntityInterface>
     */
    private function loadSubmissionDocuments(int|string $submissionId): array
    {
        return array_values(array_filter(
            $this->documentStorage->loadMultiple($this->documentStorage->getQuery()->execute()),
            static fn (EntityInterface $document): bool => (int) ($document->get('submission_id') ?? 0) === (int) $submissionId,
        ));
    }

    private function persistBundleDocument(EntityInterface $submission, string $bundlePath, string $bundleFilename, array $included): void
    {
        $document = null;
        foreach ($this->documentStorage->loadMultiple($this->documentStorage->getQuery()->execute()) as $existingDocument) {
            if ((int) ($existingDocument->get('submission_id') ?? 0) !== (int) $submission->id()) {
                continue;
            }
            if ((string) ($existingDocument->get('document_type') ?? '') !== 'artifact_bundle_zip') {
                continue;
            }
            $document = $existingDocument;
            break;
        }

        $document ??= $this->documentStorage->create([
            'label' => sprintf('Artifact Bundle ZIP #%s', (string) $submission->id()),
        ]);

        $document->set('label', sprintf('Artifact Bundle ZIP #%s', (string) $submission->id()));
        $document->set('submission_id', $submission->id());
        $document->set('document_type', 'artifact_bundle_zip');
        $document->set('format', 'zip');
        $document->set('version', 'bundle-v1');
        $document->set('storage_path', $bundlePath);
        $document->set('source_hash', hash_file('sha256', $bundlePath) ?: hash('sha256', $bundlePath));
        $document->set('metadata', [
            'filename' => $bundleFilename,
            'size' => filesize($bundlePath) ?: 0,
            'included_documents' => $included,
        ]);
        $document->set('generated_at', gmdate(DATE_ATOM));
        $this->documentStorage->save($document);
    }

    private function bundleFilename(EntityInterface $submission): string
    {
        $base = strtolower((string) ($submission->label() ?? 'artifact-bundle'));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'artifact-bundle';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'artifact-bundle';
        }

        return $base . '-bundle.zip';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function appliedResearchDrafts(EntityInterface $submission): array
    {
        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];

        return array_values(array_map(
            static fn (array $draft): array => [
                'target_path' => (string) ($draft['target_path'] ?? ''),
                'source_query' => (string) ($draft['source_query'] ?? ''),
                'source_provider' => (string) ($draft['source_provider'] ?? ''),
                'draft_quality' => (string) ($draft['draft_quality'] ?? ''),
                'applied_at' => (string) ($draft['applied_at'] ?? ''),
            ],
            array_filter(
                $drafts,
                static fn (mixed $draft): bool => is_array($draft) && (string) ($draft['status'] ?? '') === 'applied',
            ),
        ));
    }
}
