<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Review\ProposalReviewService;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class PdfGenerationService
{
    public function __construct(
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly EntityStorageInterface $documentStorage,
        private readonly ProposalReviewService $reviewService,
        private readonly string $projectRoot,
        private readonly string $chromeBinary = '/usr/bin/google-chrome',
    ) {}

    /**
     * @return array{path: string, filename: string, document_type: string}
     */
    public function generatePackagePdf(EntityInterface $submission): array
    {
        if (!is_executable($this->chromeBinary)) {
            throw new \RuntimeException(sprintf('Chrome binary not executable at "%s".', $this->chromeBinary));
        }

        $package = $this->documentPreviewService->buildPackageAndPersist($submission);
        $slug = $this->slugify((string) ($submission->get('business_name') ?: $submission->label() ?: 'submission'));
        $baseDir = $this->projectRoot . '/storage/proposals/generated/' . (string) $submission->id();

        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException(sprintf('Unable to create PDF output directory "%s".', $baseDir));
        }

        $htmlPath = $baseDir . '/merged-package.html';
        $pdfPath = $baseDir . '/' . $slug . '-iset-package.pdf';

        $html = $this->buildPrintableHtml($package['html']);
        if (file_put_contents($htmlPath, $html) === false) {
            throw new \RuntimeException(sprintf('Unable to write package HTML to "%s".', $htmlPath));
        }

        $command = sprintf(
            '%s --headless=new --disable-gpu --no-sandbox --run-all-compositor-stages-before-draw --print-to-pdf=%s %s 2>&1',
            escapeshellarg($this->chromeBinary),
            escapeshellarg($pdfPath),
            escapeshellarg('file://' . $htmlPath),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($pdfPath) || filesize($pdfPath) === 0) {
            throw new \RuntimeException(sprintf(
                'Chrome PDF generation failed (exit %d): %s',
                $exitCode,
                trim(implode("\n", $output)),
            ));
        }

        $document = $this->documentStorage->loadByKey('document_type', 'merged_package_pdf');
        if ($document === null || (int) ($document->get('submission_id') ?? 0) !== (int) $submission->id()) {
            $document = null;
            foreach ($this->documentStorage->loadMultiple($this->documentStorage->getQuery()->execute()) as $existingDocument) {
                if ((int) ($existingDocument->get('submission_id') ?? 0) !== (int) $submission->id()) {
                    continue;
                }
                if ((string) ($existingDocument->get('document_type') ?? '') !== 'merged_package_pdf') {
                    continue;
                }
                $document = $existingDocument;
                break;
            }
        }

        $document ??= $this->documentStorage->create([
            'label' => sprintf('Merged ISET Package PDF #%s', (string) $submission->id()),
        ]);

        $document->set('label', sprintf('Merged ISET Package PDF #%s', (string) $submission->id()));
        $document->set('submission_id', $submission->id());
        $document->set('document_type', 'merged_package_pdf');
        $document->set('format', 'pdf');
        $document->set('version', 'pdf-v1');
        $document->set('storage_path', $pdfPath);
        $document->set('source_hash', hash_file('sha256', $pdfPath) ?: hash('sha256', $pdfPath));
        $document->set('metadata', [
            'html_source' => $htmlPath,
            'filename' => basename($pdfPath),
            'size' => filesize($pdfPath) ?: 0,
        ]);
        $document->set('generated_at', gmdate(DATE_ATOM));
        $this->documentStorage->save($document);

        $this->reviewService->markExportedAfterPdfGeneration($submission->id(), basename($pdfPath));

        return [
            'path' => $pdfPath,
            'filename' => basename($pdfPath),
            'document_type' => 'merged_package_pdf',
        ];
    }

    private function buildPrintableHtml(string $body): string
    {
        return sprintf(
            <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ISET Package PDF</title>
  <style>%s</style>
</head>
<body>
  <main>
    <div class="stack">%s</div>
  </main>
</body>
</html>
HTML,
            $this->documentPreviewService->pageStyles(),
            $body,
        );
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'submission';
        return trim($value, '-') ?: 'submission';
    }
}
