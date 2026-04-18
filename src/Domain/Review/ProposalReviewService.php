<?php

declare(strict_types=1);

namespace App\Domain\Review;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class ProposalReviewService
{
    public function __construct(
        private readonly EntityStorageInterface $submissionStorage,
        private readonly EntityStorageInterface $reviewStorage,
    ) {}

    public function loadSubmission(int|string $submissionId): ?EntityInterface
    {
        return $this->submissionStorage->load($submissionId);
    }

    /**
     * @return array{review_count:int,latest_action:?string,latest_title:?string,latest_comment:?string,latest_section:?string,latest_field:?string,latest_created_at:?string,status:string,current_step:string,has_revisions_requested:bool,is_approved:bool,reviewed_appendix_count:int,reviewed_appendix_total:int}
     */
    public function summarizeSubmission(int|string|EntityInterface $submission): array
    {
        $submissionEntity = $submission instanceof EntityInterface
            ? $submission
            : $this->loadSubmission($submission);

        if ($submissionEntity === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submission));
        }

        $reviews = $this->loadReviews($submissionEntity->id());
        $latest = $this->preferredSummaryReview($reviews);
        $status = (string) ($submissionEntity->get('status') ?? 'draft');
        $currentStep = (string) ($submissionEntity->get('current_step') ?? '');
        $reviewedAppendices = $this->reviewedAppendices($submissionEntity);

        return [
            'review_count' => count($reviews),
            'latest_action' => $latest instanceof EntityInterface ? (string) ($latest->get('action_type') ?? '') : null,
            'latest_title' => $latest instanceof EntityInterface ? (string) ($latest->label() ?? '') : null,
            'latest_comment' => $latest instanceof EntityInterface ? (string) ($latest->get('comment') ?? '') : null,
            'latest_section' => $latest instanceof EntityInterface ? (string) ($latest->get('section_key') ?? '') : null,
            'latest_field' => $latest instanceof EntityInterface ? (string) ($latest->get('field_path') ?? '') : null,
            'latest_created_at' => $latest instanceof EntityInterface ? (string) ($latest->get('created_at') ?? '') : null,
            'status' => $status,
            'current_step' => $currentStep,
            'has_revisions_requested' => $status === 'revisions_requested',
            'is_approved' => in_array($status, ['approved', 'exported', 'submitted'], true),
            'reviewed_appendix_count' => count(array_filter($reviewedAppendices, static fn (array $state): bool => (bool) ($state['reviewed'] ?? false))),
            'reviewed_appendix_total' => count($reviewedAppendices),
        ];
    }

    /**
     * @param array<int, EntityInterface> $reviews
     */
    private function preferredSummaryReview(array $reviews): ?EntityInterface
    {
        foreach ($reviews as $review) {
            if ((string) ($review->get('section_key') ?? '') === 'appendix_review') {
                continue;
            }

            return $review;
        }

        return $reviews[0] ?? null;
    }

    /**
     * @return array<int, EntityInterface>
     */
    public function loadReviews(int|string $submissionId): array
    {
        $ids = $this->reviewStorage->getQuery()->execute();
        $reviews = array_values(array_filter(
            $this->reviewStorage->loadMultiple($ids),
            static fn (EntityInterface $review): bool => (int) ($review->get('submission_id') ?? 0) === (int) $submissionId,
        ));
        usort($reviews, static function (EntityInterface $a, EntityInterface $b): int {
            return (int) $b->id() <=> (int) $a->id();
        });

        return $reviews;
    }

    public function addComment(
        int|string $submissionId,
        string $comment,
        string $sectionKey = '',
        string $fieldPath = '',
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $trimmed = trim($comment);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Review comment cannot be empty.');
        }

        $review = $this->reviewStorage->create([
            'title' => sprintf('Comment on %s', (string) ($submission->label() ?? 'submission')),
        ]);
        $review->set('title', sprintf('Comment on %s', (string) ($submission->label() ?? 'submission')));
        $review->set('submission_id', $submission->id());
        $review->set('reviewer_uid', $reviewerUid);
        $review->set('section_key', $sectionKey);
        $review->set('field_path', $fieldPath);
        $review->set('comment', $trimmed);
        $review->set('action_type', 'comment');
        $review->set('created_at', gmdate(DATE_ATOM));
        $this->reviewStorage->save($review);
    }

    /**
     * @return list<array{section_key:string,field_path:string,comment:string,action_type:string,created_at:string,title:string}>
     */
    public function fieldAnnotations(int|string $submissionId): array
    {
        $annotations = [];

        foreach ($this->loadReviews($submissionId) as $review) {
            $fieldPath = trim((string) ($review->get('field_path') ?? ''));
            if ($fieldPath === '' || $fieldPath === 'status') {
                continue;
            }

            if ((string) ($review->get('section_key') ?? '') === 'appendix_review') {
                continue;
            }

            $annotations[] = [
                'section_key' => (string) ($review->get('section_key') ?? ''),
                'field_path' => $fieldPath,
                'comment' => (string) ($review->get('comment') ?? ''),
                'action_type' => (string) ($review->get('action_type') ?? ''),
                'created_at' => (string) ($review->get('created_at') ?? ''),
                'title' => (string) ($review->label() ?? ''),
            ];
        }

        return $annotations;
    }

    /**
     * @return array<string, array{comment:string,created_at:string,title:string}>
     */
    public function latestAppendixNotes(int|string $submissionId): array
    {
        $notes = [];

        foreach ($this->loadReviews($submissionId) as $review) {
            if ((string) ($review->get('section_key') ?? '') !== 'appendix_review') {
                continue;
            }

            $fieldPath = trim((string) ($review->get('field_path') ?? ''));
            if (!preg_match('/^appendix\.([ABFGHM])$/', $fieldPath, $matches)) {
                continue;
            }

            $appendix = $matches[1];
            if (isset($notes[$appendix])) {
                continue;
            }

            $actionType = (string) ($review->get('action_type') ?? '');
            if ($actionType === 'appendix_note_cleared') {
                $notes[$appendix] = [
                    'comment' => '',
                    'created_at' => '',
                    'title' => '',
                ];
                continue;
            }

            if (!in_array($actionType, ['comment', 'appendix_note_restored'], true)) {
                continue;
            }

            $notes[$appendix] = [
                'comment' => (string) ($review->get('comment') ?? ''),
                'created_at' => (string) ($review->get('created_at') ?? ''),
                'title' => (string) ($review->label() ?? ''),
            ];
        }

        return $notes;
    }

    /**
     * @return array<string, array{action_type:string,created_at:string,title:string,comment:string}>
     */
    public function latestAppendixNoteActivity(int|string $submissionId): array
    {
        $activity = [];

        foreach ($this->loadReviews($submissionId) as $review) {
            if ((string) ($review->get('section_key') ?? '') !== 'appendix_review') {
                continue;
            }

            $fieldPath = trim((string) ($review->get('field_path') ?? ''));
            if (!preg_match('/^appendix\.([ABFGHM])$/', $fieldPath, $matches)) {
                continue;
            }

            $actionType = (string) ($review->get('action_type') ?? '');
            if (!in_array($actionType, ['comment', 'appendix_note_cleared', 'appendix_note_restored'], true)) {
                continue;
            }

            $appendix = $matches[1];
            if (isset($activity[$appendix])) {
                continue;
            }

            $activity[$appendix] = [
                'action_type' => $actionType,
                'created_at' => (string) ($review->get('created_at') ?? ''),
                'title' => (string) ($review->label() ?? ''),
                'comment' => (string) ($review->get('comment') ?? ''),
            ];
        }

        return $activity;
    }

    /**
     * @return array<string, array{comment:string,created_at:string,title:string}>
     */
    public function recoverableAppendixNotes(int|string $submissionId): array
    {
        $recoverable = [];
        $awaitingPreviousNote = [];

        foreach ($this->loadReviews($submissionId) as $review) {
            if ((string) ($review->get('section_key') ?? '') !== 'appendix_review') {
                continue;
            }

            $fieldPath = trim((string) ($review->get('field_path') ?? ''));
            if (!preg_match('/^appendix\.([ABFGHM])$/', $fieldPath, $matches)) {
                continue;
            }

            $appendix = $matches[1];
            $actionType = (string) ($review->get('action_type') ?? '');

            if ($actionType === 'appendix_note_cleared') {
                $awaitingPreviousNote[$appendix] = true;
                continue;
            }

            if (!($awaitingPreviousNote[$appendix] ?? false)) {
                continue;
            }

            if (!in_array($actionType, ['comment', 'appendix_note_restored'], true)) {
                continue;
            }

            $recoverable[$appendix] = [
                'comment' => (string) ($review->get('comment') ?? ''),
                'created_at' => (string) ($review->get('created_at') ?? ''),
                'title' => (string) ($review->label() ?? ''),
            ];
            unset($awaitingPreviousNote[$appendix]);
        }

        return $recoverable;
    }

    public function clearAppendixNote(
        int|string $submissionId,
        string $appendix,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $appendix = strtoupper(trim($appendix));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported appendix "%s".', $appendix));
        }

        $this->recordSystemAction(
            $submissionId,
            sprintf('Appendix %s note cleared', $appendix),
            sprintf('Appendix %s review note was cleared.', $appendix),
            'appendix_review',
            sprintf('appendix.%s', $appendix),
            'appendix_note_cleared',
            $reviewerUid,
        );
    }

    public function restoreAppendixNote(
        int|string $submissionId,
        string $appendix,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $appendix = strtoupper(trim($appendix));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported appendix "%s".', $appendix));
        }

        $recoverable = $this->recoverableAppendixNotes($submissionId);
        $note = $recoverable[$appendix] ?? null;
        if (!is_array($note) || trim((string) ($note['comment'] ?? '')) === '') {
            throw new \InvalidArgumentException(sprintf('No recoverable appendix note found for "%s".', $appendix));
        }

        $this->recordSystemAction(
            $submissionId,
            sprintf('Appendix %s note restored', $appendix),
            (string) $note['comment'],
            'appendix_review',
            sprintf('appendix.%s', $appendix),
            'appendix_note_restored',
            $reviewerUid,
        );
    }

    public function transitionStatus(
        int|string $submissionId,
        string $status,
        string $note = '',
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $status = trim($status);
        if ($status === '') {
            throw new \InvalidArgumentException('Submission status cannot be empty.');
        }

        if (in_array($status, ['approved', 'exported', 'submitted'], true) && !$this->allAppendicesReviewed($submission)) {
            throw new \InvalidArgumentException(sprintf(
                'All appendices must be reviewed before moving a submission to %s. Remaining: %s.',
                $status,
                $this->pendingAppendixSummary($submission),
            ));
        }

        $submission->set('status', $status);
        $submission->set('current_step', $this->stepForStatus($status));
        $this->submissionStorage->save($submission);

        $review = $this->reviewStorage->create([
            'title' => sprintf('Status changed to %s', $status),
        ]);
        $review->set('title', sprintf('Status changed to %s', $status));
        $review->set('submission_id', $submission->id());
        $review->set('reviewer_uid', $reviewerUid);
        $review->set('section_key', 'workflow');
        $review->set('field_path', 'status');
        $review->set('comment', trim($note) !== '' ? trim($note) : sprintf('Submission moved to %s.', $status));
        $review->set('action_type', 'status_change');
        $review->set('created_at', gmdate(DATE_ATOM));
        $this->reviewStorage->save($review);
    }

    public function sendBackToIntake(
        int|string $submissionId,
        string $note,
        int $reviewerUid = 1,
    ): void {
        $trimmed = trim($note);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('A note is required when sending a submission back to intake.');
        }

        $this->transitionStatus(
            $submissionId,
            'intake_in_progress',
            $trimmed,
            $reviewerUid,
        );
    }

    /**
     * @return array<string, array{reviewed:bool,reviewed_at:string,reviewer_uid:int}>
     */
    public function reviewedAppendices(int|string|EntityInterface $submission): array
    {
        $submissionEntity = $submission instanceof EntityInterface
            ? $submission
            : $this->loadSubmission($submission);

        if ($submissionEntity === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submission));
        }

        $validationState = $submissionEntity->get('validation_state');
        $reviewed = is_array($validationState['reviewed_appendices'] ?? null)
            ? $validationState['reviewed_appendices']
            : [];

        $normalized = [];
        foreach (['A', 'B', 'F', 'G', 'H', 'M'] as $appendix) {
            $state = $reviewed[$appendix] ?? null;
            if (!is_array($state)) {
                $normalized[$appendix] = [
                    'reviewed' => false,
                    'reviewed_at' => '',
                    'reviewer_uid' => 0,
                ];
                continue;
            }

            $normalized[$appendix] = [
                'reviewed' => (bool) ($state['reviewed'] ?? false),
                'reviewed_at' => (string) ($state['reviewed_at'] ?? ''),
                'reviewer_uid' => (int) ($state['reviewer_uid'] ?? 0),
            ];
        }

        return $normalized;
    }

    public function markAppendixReviewed(
        int|string $submissionId,
        string $appendix,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $appendix = strtoupper(trim($appendix));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported appendix "%s".', $appendix));
        }

        $validationState = $submission->get('validation_state');
        $validationState = is_array($validationState) ? $validationState : [];
        $reviewedAppendices = is_array($validationState['reviewed_appendices'] ?? null)
            ? $validationState['reviewed_appendices']
            : [];

        $reviewedAppendices[$appendix] = [
            'reviewed' => true,
            'reviewed_at' => gmdate(DATE_ATOM),
            'reviewer_uid' => $reviewerUid,
        ];

        $validationState['reviewed_appendices'] = $reviewedAppendices;
        $submission->set('validation_state', $validationState);
        $this->submissionStorage->save($submission);

        $this->recordSystemAction(
            $submissionId,
            sprintf('Appendix %s marked reviewed', $appendix),
            sprintf('Appendix %s was marked reviewed by staff.', $appendix),
            'appendix_review',
            sprintf('appendix.%s', $appendix),
            'appendix_reviewed',
            $reviewerUid,
        );
    }

    public function clearAppendixReviewed(
        int|string $submissionId,
        string $appendix,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $appendix = strtoupper(trim($appendix));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported appendix "%s".', $appendix));
        }

        $validationState = $submission->get('validation_state');
        $validationState = is_array($validationState) ? $validationState : [];
        $reviewedAppendices = is_array($validationState['reviewed_appendices'] ?? null)
            ? $validationState['reviewed_appendices']
            : [];

        $reviewedAppendices[$appendix] = [
            'reviewed' => false,
            'reviewed_at' => '',
            'reviewer_uid' => $reviewerUid,
        ];

        $validationState['reviewed_appendices'] = $reviewedAppendices;
        $submission->set('validation_state', $validationState);
        $this->submissionStorage->save($submission);

        $this->recordSystemAction(
            $submissionId,
            sprintf('Appendix %s review cleared', $appendix),
            sprintf('Appendix %s was reopened for staff review.', $appendix),
            'appendix_review',
            sprintf('appendix.%s', $appendix),
            'appendix_review_cleared',
            $reviewerUid,
        );
    }

    public function markAllAppendicesReviewed(
        int|string $submissionId,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $validationState = $submission->get('validation_state');
        $validationState = is_array($validationState) ? $validationState : [];
        $reviewedAppendices = [];
        $reviewedAt = gmdate(DATE_ATOM);

        foreach (['A', 'B', 'F', 'G', 'H', 'M'] as $appendix) {
            $reviewedAppendices[$appendix] = [
                'reviewed' => true,
                'reviewed_at' => $reviewedAt,
                'reviewer_uid' => $reviewerUid,
            ];
        }

        $validationState['reviewed_appendices'] = $reviewedAppendices;
        $submission->set('validation_state', $validationState);
        $this->submissionStorage->save($submission);

        $this->recordSystemAction(
            $submissionId,
            'All appendices marked reviewed',
            'Staff bulk-marked appendices A, B, F, G, H, and M as reviewed.',
            'appendix_review',
            'appendix.all',
            'appendix_reviewed_bulk',
            $reviewerUid,
        );
    }

    public function clearAllAppendixReviews(
        int|string $submissionId,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $validationState = $submission->get('validation_state');
        $validationState = is_array($validationState) ? $validationState : [];
        $reviewedAppendices = [];

        foreach (['A', 'B', 'F', 'G', 'H', 'M'] as $appendix) {
            $reviewedAppendices[$appendix] = [
                'reviewed' => false,
                'reviewed_at' => '',
                'reviewer_uid' => $reviewerUid,
            ];
        }

        $validationState['reviewed_appendices'] = $reviewedAppendices;
        $submission->set('validation_state', $validationState);
        $this->submissionStorage->save($submission);

        $this->recordSystemAction(
            $submissionId,
            'All appendix review states cleared',
            'Staff bulk-cleared appendix review state for A, B, F, G, H, and M.',
            'appendix_review',
            'appendix.all',
            'appendix_review_cleared_bulk',
            $reviewerUid,
        );
    }

    public function recordSystemAction(
        int|string $submissionId,
        string $title,
        string $comment,
        string $sectionKey = 'workflow',
        string $fieldPath = 'status',
        string $actionType = 'system',
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $review = $this->reviewStorage->create([
            'title' => $title,
        ]);
        $review->set('title', $title);
        $review->set('submission_id', $submission->id());
        $review->set('reviewer_uid', $reviewerUid);
        $review->set('section_key', $sectionKey);
        $review->set('field_path', $fieldPath);
        $review->set('comment', trim($comment));
        $review->set('action_type', $actionType);
        $review->set('created_at', gmdate(DATE_ATOM));
        $this->reviewStorage->save($review);
    }

    public function markExportedAfterPdfGeneration(
        int|string $submissionId,
        string $filename,
        int $reviewerUid = 1,
    ): void {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $status = (string) ($submission->get('status') ?? 'draft');
        if ($status !== 'approved') {
            return;
        }

        $this->transitionStatus(
            $submissionId,
            'exported',
            sprintf('Final package PDF generated: %s', $filename),
            $reviewerUid,
        );
    }

    public function allAppendicesReviewed(int|string|EntityInterface $submission): bool
    {
        foreach ($this->reviewedAppendices($submission) as $state) {
            if (($state['reviewed'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public function pendingAppendixSummary(int|string|EntityInterface $submission): string
    {
        $pending = [];
        foreach ($this->reviewedAppendices($submission) as $appendix => $state) {
            if (($state['reviewed'] ?? false) !== true) {
                $pending[] = $appendix;
            }
        }

        return $pending === [] ? 'none' : implode(', ', $pending);
    }

    private function stepForStatus(string $status): string
    {
        return match ($status) {
            'intake_in_progress' => 'intake',
            'ready_for_review' => 'review',
            'revisions_requested' => 'revisions',
            'approved' => 'approved',
            'exported' => 'exported',
            'submitted' => 'submitted',
            default => 'structured_data',
        };
    }
}
