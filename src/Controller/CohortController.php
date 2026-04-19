<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cohort\CohortBundleService;
use App\Domain\Cohort\CohortOverviewService;
use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Entity\EntityInterface;

final class CohortController
{
    public function __construct(
        private readonly CohortOverviewService $cohortOverview,
        private readonly CohortBundleService $cohortBundleService,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
        private readonly Environment $twig,
    ) {}

    public function index(): Response
    {
        $cohorts = $this->cohortOverview->loadCohorts();
        $cards = [];
        foreach ($cohorts as $cohort) {
            $cards[] = $this->buildCohortCardView($cohort);
        }

        $html = $this->twig->render('pages/cohorts/index.html.twig', [
            'cards' => $cards,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function show(int|string $cohortId): Response
    {
        $cohort = $this->findCohort($cohortId);
        if (!$cohort instanceof EntityInterface) {
            return new Response('Cohort not found.', 404);
        }

        $summary = $this->cohortOverview->summarizeCohort($cohort);
        $submissions = $this->cohortOverview->loadSubmissionsForCohort($cohort->id());

        $rows = [];
        $attentionItems = [];
        foreach ($submissions as $submission) {
            $rows[] = $this->buildSubmissionRowView($submission);
            $attentionItem = $this->buildAttentionItemView($submission);
            if ($attentionItem !== null) {
                $attentionItems[] = $attentionItem;
            }
        }

        $html = $this->twig->render('pages/cohorts/show.html.twig', [
            'cohort' => [
                'id' => (string) $cohort->id(),
                'title' => (string) ($cohort->label() ?? 'Cohort'),
            ],
            'summary' => [
                'total' => (int) $summary['total'],
                'ready_for_review' => (int) $summary['ready_for_review'],
                'approved_track' => (int) $summary['approved_track'],
                'exported' => (int) $summary['exported'],
                'weak_fields' => (int) $summary['weak_fields'],
            ],
            'rows' => $rows,
            'attention_items' => $attentionItems,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function exportCsv(int|string $cohortId): Response
    {
        $cohort = $this->findCohort($cohortId);
        if (!$cohort instanceof EntityInterface) {
            return new Response('Cohort not found.', 404);
        }

        $submissions = $this->cohortOverview->loadSubmissionsForCohort($cohort->id());
        $filename = $this->csvFilename($cohort);

        $response = new StreamedResponse(function () use ($submissions): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, [
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
                'artifacts_ready',
                'latest_review_at',
            ], ',', '"', '');

            foreach ($submissions as $submission) {
                $review = $this->reviewService->summarizeSubmission($submission);
                $readiness = $this->cohortOverview->readinessSummary($submission);
                $artifactAudit = $this->artifactAuditService->summarize($submission);

                fputcsv($output, [
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
                    sprintf('%d/%d', (int) $artifactAudit['ready_count'], (int) $artifactAudit['total_count']),
                    (string) ($review['latest_created_at'] ?? ''),
                ], ',', '"', '');
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            (new ResponseHeaderBag())->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    public function downloadBundle(int|string $cohortId): Response
    {
        $cohort = $this->findCohort($cohortId);
        if (!$cohort instanceof EntityInterface) {
            return new Response('Cohort not found.', 404);
        }

        $bundle = $this->cohortBundleService->build($cohort);
        $response = new BinaryFileResponse($bundle['path']);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $bundle['filename']);

        return $response;
    }

    private function findCohort(int|string $cohortId): ?EntityInterface
    {
        foreach ($this->cohortOverview->loadCohorts() as $loadedCohort) {
            if ((string) $loadedCohort->id() === (string) $cohortId) {
                return $loadedCohort;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCohortCardView(EntityInterface $cohort): array
    {
        $summary = $this->cohortOverview->summarizeCohort($cohort);
        $url = '/cohorts/' . rawurlencode((string) $cohort->id());

        return [
            'url' => $url,
            'label' => (string) ($cohort->label() ?? 'Cohort'),
            'status' => (string) ($cohort->get('status') ?? 'n/a'),
            'capacity' => (string) ($cohort->get('capacity') ?? 'n/a'),
            'starts_at' => (string) ($cohort->get('starts_at') ?? 'n/a'),
            'ends_at' => (string) ($cohort->get('ends_at') ?? 'n/a'),
            'total' => (int) $summary['total'],
            'ready_plus_approved' => (int) $summary['ready_for_review'] + (int) $summary['approved_track'],
            'reviewed_appendices' => (int) $summary['reviewed_appendices'],
            'appendix_total' => (int) $summary['appendix_total'],
            'weak_fields' => (int) $summary['weak_fields'],
        ];
    }

    private function csvFilename(EntityInterface $cohort): string
    {
        $base = strtolower((string) ($cohort->label() ?? 'cohort'));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'cohort';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'cohort';
        }

        return $base . '-board.csv';
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSubmissionRowView(EntityInterface $submission): array
    {
        $review = $this->reviewService->summarizeSubmission($submission);
        $readiness = $this->cohortOverview->readinessSummary($submission);
        $artifactAudit = $this->artifactAuditService->summarize($submission);
        $submissionUrl = '/submissions/' . rawurlencode((string) $submission->id());
        $updated = (string) ($review['latest_created_at'] ?: ($submission->get('started_at') ?? 'n/a'));

        return [
            'label' => (string) ($submission->label() ?? 'Submission'),
            'applicant_name' => (string) ($submission->get('applicant_name') ?? 'Unknown applicant'),
            'business_name' => (string) ($submission->get('business_name') ?? 'Unknown business'),
            'status' => (string) ($submission->get('status') ?? 'draft'),
            'current_step' => (string) ($submission->get('current_step') ?? 'n/a'),
            'readiness_label' => (string) $readiness['label'],
            'reviewed_appendix_count' => (int) $review['reviewed_appendix_count'],
            'reviewed_appendix_total' => (int) $review['reviewed_appendix_total'],
            'review_count' => (int) $review['review_count'],
            'weak_count' => (int) $readiness['weak_count'],
            'unresolved_count' => (int) $readiness['unresolved_count'],
            'ready_count' => (int) $artifactAudit['ready_count'],
            'total_count' => (int) $artifactAudit['total_count'],
            'updated' => $updated,
            'url' => $submissionUrl,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildAttentionItemView(EntityInterface $submission): ?array
    {
        $review = $this->reviewService->summarizeSubmission($submission);
        $readiness = $this->cohortOverview->readinessSummary($submission);
        $artifactAudit = $this->artifactAuditService->summarize($submission);
        $issues = [];

        if ($readiness['weak_count'] > 0) {
            $issues[] = sprintf('%d weak field%s', $readiness['weak_count'], $readiness['weak_count'] === 1 ? '' : 's');
        }
        if ($readiness['unresolved_count'] > 0) {
            $issues[] = sprintf('%d unresolved item%s', $readiness['unresolved_count'], $readiness['unresolved_count'] === 1 ? '' : 's');
        }
        if ((int) $review['reviewed_appendix_count'] < (int) $review['reviewed_appendix_total']) {
            $issues[] = sprintf(
                '%d/%d appendices still awaiting signoff',
                (int) $review['reviewed_appendix_total'] - (int) $review['reviewed_appendix_count'],
                (int) $review['reviewed_appendix_total'],
            );
        }
        if ((int) $artifactAudit['ready_count'] < (int) $artifactAudit['total_count']) {
            $issues[] = sprintf(
                '%d/%d artifacts missing',
                (int) $artifactAudit['total_count'] - (int) $artifactAudit['ready_count'],
                (int) $artifactAudit['total_count'],
            );
        }

        if ($issues === []) {
            return null;
        }

        return [
            'label' => (string) ($submission->label() ?? 'Submission'),
            'issues' => implode(' · ', $issues),
            'url' => '/submissions/' . rawurlencode((string) $submission->id()),
        ];
    }
}
