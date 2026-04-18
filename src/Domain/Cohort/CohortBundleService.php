<?php

declare(strict_types=1);

namespace App\Domain\Cohort;

use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Generation\ArtifactBundleService;
use App\Domain\Review\ProposalReviewService;
use Waaseyaa\Entity\EntityInterface;

final class CohortBundleService
{
    public function __construct(
        private readonly CohortOverviewService $cohortOverview,
        private readonly ArtifactBundleService $artifactBundleService,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
        private readonly string $projectRoot,
    ) {}

    /**
     * @return array{path:string,filename:string}
     */
    public function build(EntityInterface $cohort): array
    {
        $submissions = $this->cohortOverview->loadSubmissionsForCohort($cohort->id());
        $outputDir = rtrim($this->projectRoot, '/') . '/storage/proposals/cohorts/' . $cohort->id();
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $filename = $this->slugify((string) ($cohort->label() ?? 'cohort')) . '-bundle.zip';
        $path = $outputDir . '/' . $filename;

        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException(sprintf('Unable to create cohort bundle "%s".', $path));
        }

        $csv = $this->buildCsv($submissions);
        $zip->addFromString('cohort-board.csv', $csv);

        $included = ['cohort-board.csv'];
        foreach ($submissions as $submission) {
            $bundle = $this->artifactBundleService->buildAndPersist($submission, true);
            $bundleName = $this->slugify((string) ($submission->label() ?? 'submission')) . '-bundle.zip';
            if ($zip->addFile($bundle['path'], 'submissions/' . $bundleName)) {
                $included[] = 'submissions/' . $bundleName;
            }
        }

        $manifest = [
            'cohort_id' => $cohort->id(),
            'cohort_label' => (string) ($cohort->label() ?? ''),
            'generated_at' => gmdate(DATE_ATOM),
            'submission_count' => count($submissions),
            'included_files' => $included,
        ];
        $zip->addFromString('cohort-manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return [
            'path' => $path,
            'filename' => $filename,
        ];
    }

    /**
     * @param array<int, EntityInterface> $submissions
     */
    private function buildCsv(array $submissions): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open temp stream for cohort CSV.');
        }

        fputcsv($stream, [
            'submission_id',
            'title',
            'applicant_name',
            'business_name',
            'status',
            'current_step',
            'readiness',
            'weak_fields',
            'unresolved_items',
            'review_actions',
            'reviewed_appendices',
            'research_backed_updates',
            'artifacts_ready',
            'latest_review_at',
        ], ',', '"', '');

        foreach ($submissions as $submission) {
            $review = $this->reviewService->summarizeSubmission($submission);
            $readiness = $this->cohortOverview->readinessSummary($submission);
            $artifactAudit = $this->artifactAuditService->summarize($submission);
            $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
            $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
            $researchBackedCount = count(array_filter(
                $drafts,
                static fn (mixed $draft): bool => is_array($draft) && (string) ($draft['status'] ?? '') === 'applied',
            ));

            fputcsv($stream, [
                (string) $submission->id(),
                (string) ($submission->label() ?? ''),
                (string) ($submission->get('applicant_name') ?? ''),
                (string) ($submission->get('business_name') ?? ''),
                (string) ($submission->get('status') ?? ''),
                (string) ($submission->get('current_step') ?? ''),
                $readiness['label'],
                (string) $readiness['weak_count'],
                (string) $readiness['unresolved_count'],
                (string) $review['review_count'],
                sprintf('%d/%d', (int) $review['reviewed_appendix_count'], (int) $review['reviewed_appendix_total']),
                (string) $researchBackedCount,
                sprintf('%d/%d', (int) $artifactAudit['ready_count'], (int) $artifactAudit['total_count']),
                (string) ($review['latest_created_at'] ?? ''),
            ], ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'cohort';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'cohort';
    }
}
