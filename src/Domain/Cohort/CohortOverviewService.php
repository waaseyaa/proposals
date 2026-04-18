<?php

declare(strict_types=1);

namespace App\Domain\Cohort;

use App\Domain\Review\ProposalReviewService;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class CohortOverviewService
{
    public function __construct(
        private readonly EntityStorageInterface $cohortStorage,
        private readonly EntityStorageInterface $submissionStorage,
        private readonly ProposalReviewService $reviewService,
    ) {}

    /**
     * @return array<int, EntityInterface>
     */
    public function loadCohorts(): array
    {
        $ids = $this->cohortStorage->getQuery()
            ->sort('id', 'ASC')
            ->execute();

        return array_values($this->cohortStorage->loadMultiple($ids));
    }

    /**
     * @return array<int, EntityInterface>
     */
    public function loadSubmissionsForCohort(int|string $cohortId): array
    {
        $ids = $this->submissionStorage->getQuery()
            ->condition('cohort_id', $cohortId)
            ->sort('id', 'ASC')
            ->execute();

        return array_values($this->submissionStorage->loadMultiple($ids));
    }

    /**
     * @return array{total:int,ready_for_review:int,approved_track:int,exported:int,weak_fields:int,reviewed_appendices:int,appendix_total:int}
     */
    public function summarizeCohort(int|string|EntityInterface $cohort): array
    {
        $cohortEntity = $cohort instanceof EntityInterface
            ? $cohort
            : $this->cohortStorage->load($cohort);

        if ($cohortEntity === null) {
            throw new \RuntimeException(sprintf('Cohort "%s" not found.', (string) $cohort));
        }

        $submissions = $this->loadSubmissionsForCohort($cohortEntity->id());
        $summary = [
            'total' => count($submissions),
            'ready_for_review' => 0,
            'approved_track' => 0,
            'exported' => 0,
            'weak_fields' => 0,
            'reviewed_appendices' => 0,
            'appendix_total' => 0,
        ];

        foreach ($submissions as $submission) {
            $readiness = $this->readinessSummary($submission);
            $review = $this->reviewService->summarizeSubmission($submission);

            if ($readiness['label'] === 'Ready for review') {
                $summary['ready_for_review']++;
            }
            if ((bool) $review['is_approved']) {
                $summary['approved_track']++;
            }
            if ((string) ($submission->get('status') ?? '') === 'exported') {
                $summary['exported']++;
            }

            $summary['weak_fields'] += $readiness['weak_count'];
            $summary['reviewed_appendices'] += (int) $review['reviewed_appendix_count'];
            $summary['appendix_total'] += (int) $review['reviewed_appendix_total'];
        }

        return $summary;
    }

    /**
     * @return array{label:string,unresolved_count:int,weak_count:int}
     */
    public function readinessSummary(EntityInterface $submission): array
    {
        $status = (string) ($submission->get('status') ?? 'draft');
        $unresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $weakCount = 0;
        $confidenceState = is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [];

        foreach ($confidenceState as $state) {
            if (is_array($state) && isset($state['confidence']) && ($state['resolved'] ?? true) === false) {
                $weakCount++;
            }
        }

        $label = match (true) {
            $status === 'submitted' => 'Submitted',
            $status === 'exported' => 'Exported',
            $status === 'approved' => 'Approved and clean',
            $weakCount > 0 => 'Needs stronger answers',
            $unresolved !== [] => 'Missing required fields',
            $status === 'ready_for_review' => 'Ready for review',
            $status === 'intake_in_progress' && $weakCount === 0 && $unresolved === [] => 'Ready for review',
            default => 'In progress',
        };

        return [
            'label' => $label,
            'unresolved_count' => count($unresolved),
            'weak_count' => $weakCount,
        ];
    }
}
