<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ReviewController
{
    public function __construct(
        private readonly ProposalReviewService $reviewService,
        private readonly Environment $twig,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->reviewService->loadSubmission($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $reviews = $this->reviewService->loadReviews($submissionId);
        $rawTimeline = isset($_GET['raw']) && (string) $_GET['raw'] === '1';
        $timelineEntries = $rawTimeline
            ? array_map(fn (object $review): array => $this->buildReviewEntryView($review), $reviews)
            : $this->buildTimelineEntries($reviews);

        $confidenceState = is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [];
        $completionState = is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [];
        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];

        $submissionIdString = (string) $submission->id();

        $html = $this->twig->render('pages/reviews/show.html.twig', [
            'title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => $submissionIdString,
            'status' => (string) ($submission->get('status') ?? 'draft'),
            'notice' => $this->noticeKeyFromUri($_SERVER['REQUEST_URI'] ?? ''),
            'prefill_section' => trim((string) ($_GET['section_key'] ?? '')),
            'prefill_field' => trim((string) ($_GET['field_path'] ?? '')),
            'prefill_comment' => trim((string) ($_GET['comment'] ?? '')),
            'workflow_warning' => $this->weakFieldSummary($confidenceState),
            'completion_state' => $completionState,
            'appendix_review' => $this->buildAppendixReviewView(
                $this->reviewService->reviewedAppendices($submission),
                $this->reviewService->latestAppendixNotes($submissionId),
                $this->reviewService->latestAppendixNoteActivity($submissionId),
                $this->reviewService->recoverableAppendixNotes($submissionId),
                $submissionIdString,
            ),
            'appendix_review_warning' => $this->appendixReviewWarning($submission),
            'confidence_items' => $this->buildConfidenceView($confidenceState, $submissionIdString),
            'research_backed' => $this->buildResearchBackedView($validationState, $submissionIdString),
            'timeline_entries' => $timelineEntries,
            'timeline_mode' => $rawTimeline ? 'Raw audit mode' : 'Compact narrative mode',
            'timeline_toggle_label' => $rawTimeline ? 'Show Compact Timeline' : 'Show Raw Audit Trail',
            'timeline_toggle_href' => $rawTimeline
                ? '/submissions/' . rawurlencode($submissionIdString) . '/review'
                : '/submissions/' . rawurlencode($submissionIdString) . '/review?raw=1',
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function addComment(Request $request, int|string $submissionId): Response
    {
        try {
            $this->reviewService->addComment(
                $submissionId,
                (string) $request->request->get('comment', ''),
                (string) $request->request->get('section_key', ''),
                (string) $request->request->get('field_path', ''),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=comment');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?comment=added');
    }

    public function addAppendixNote(Request $request, int|string $submissionId): Response
    {
        $appendix = strtoupper(trim((string) $request->request->get('appendix', '')));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        try {
            $this->reviewService->addComment(
                $submissionId,
                (string) $request->request->get('comment', ''),
                'appendix_review',
                'appendix.' . $appendix,
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix-note=added');
    }

    public function clearAppendixNote(Request $request, int|string $submissionId): Response
    {
        $appendix = strtoupper(trim((string) $request->request->get('appendix', '')));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        try {
            $this->reviewService->clearAppendixNote($submissionId, $appendix);
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix-note=cleared');
    }

    public function restoreAppendixNote(Request $request, int|string $submissionId): Response
    {
        $appendix = strtoupper(trim((string) $request->request->get('appendix', '')));
        if (!in_array($appendix, ['A', 'B', 'F', 'G', 'H', 'M'], true)) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        try {
            $this->reviewService->restoreAppendixNote($submissionId, $appendix);
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-note');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix-note=restored');
    }

    public function updateStatus(Request $request, int|string $submissionId): Response
    {
        try {
            $submission = $this->reviewService->loadSubmission($submissionId);
            if ($submission === null) {
                return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=status');
            }

            $status = (string) $request->request->get('status', '');
            $note = (string) $request->request->get('note', '');
            $weakFieldSummary = $this->weakFieldSummary(
                is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
            );

            if (in_array($status, ['approved', 'exported', 'submitted'], true) && $weakFieldSummary !== '') {
                $note = trim($note);
                $note = ($note !== '' ? $note . ' ' : '') . 'Low-confidence fields remain: ' . $weakFieldSummary;
            }

            $this->reviewService->transitionStatus(
                $submissionId,
                $status,
                $note,
            );
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'All appendices must be reviewed')) {
                return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix-review-required');
            }

            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=status');
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=status');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?status=updated');
    }

    public function sendBackToIntake(Request $request, int|string $submissionId): Response
    {
        try {
            $this->reviewService->sendBackToIntake(
                $submissionId,
                (string) $request->request->get('note', ''),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=send-back');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?sent-back=intake');
    }

    public function markAppendixReviewed(Request $request, int|string $submissionId): Response
    {
        try {
            $this->reviewService->markAppendixReviewed(
                $submissionId,
                (string) $request->request->get('appendix', ''),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix=reviewed');
    }

    public function clearAppendixReviewed(Request $request, int|string $submissionId): Response
    {
        try {
            $this->reviewService->clearAppendixReviewed(
                $submissionId,
                (string) $request->request->get('appendix', ''),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix=cleared');
    }

    public function markAllAppendicesReviewed(int|string $submissionId): Response
    {
        try {
            $this->reviewService->markAllAppendicesReviewed($submissionId);
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix=reviewed-all');
    }

    public function clearAllAppendixReviews(int|string $submissionId): Response
    {
        try {
            $this->reviewService->clearAllAppendixReviews($submissionId);
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?error=appendix');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/review?appendix=cleared-all');
    }

    /**
     * @param array<int, object> $reviews
     * @return list<array<string,mixed>>
     */
    private function buildTimelineEntries(array $reviews): array
    {
        $entries = [];
        $index = 0;
        $count = count($reviews);

        while ($index < $count) {
            $review = $reviews[$index];
            if ($this->isAppendixTimelineChurn($review)) {
                $group = [];
                while ($index < $count && $this->isAppendixTimelineChurn($reviews[$index])) {
                    $group[] = $reviews[$index];
                    $index++;
                }

                $entries[] = $this->buildAppendixTimelineGroupView($group);
                continue;
            }

            if ($this->isWorkflowTimelineChurn($review)) {
                $group = [];
                while ($index < $count && $this->isWorkflowTimelineChurn($reviews[$index])) {
                    $group[] = $reviews[$index];
                    $index++;
                }

                $entries[] = $this->buildWorkflowTimelineGroupView($group);
                continue;
            }

            if ($this->isIntakeTimelineChurn($review)) {
                $group = [];
                $groupKey = $this->intakeTimelineGroupKey($review);
                while (
                    $index < $count
                    && $this->isIntakeTimelineChurn($reviews[$index])
                    && $this->intakeTimelineGroupKey($reviews[$index]) === $groupKey
                ) {
                    $group[] = $reviews[$index];
                    $index++;
                }

                $entries[] = $this->buildIntakeTimelineGroupView($group);
                continue;
            }

            $entries[] = $this->buildReviewEntryView($review);
            $index++;
        }

        return $entries;
    }

    /**
     * @return array{kind:string,title:string,meta:string,action_type:string,body:string,body_is_raw:bool}
     */
    private function buildReviewEntryView(object $review): array
    {
        $title = (string) ($review->label() ?? 'Review Action');
        $actionType = (string) ($review->get('action_type') ?? 'review');
        $comment = (string) ($review->get('comment') ?? '');
        $sectionKey = (string) ($review->get('section_key') ?? '');
        $fieldPath = (string) ($review->get('field_path') ?? '');
        $createdAt = (string) ($review->get('created_at') ?? '');

        $meta = trim(implode(' · ', array_filter([$sectionKey, $fieldPath, $createdAt])));

        return [
            'kind' => 'review',
            'title' => $title,
            'meta' => $meta,
            'action_type' => $actionType,
            'body' => $comment !== '' ? nl2br(htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : '',
            'body_is_raw' => $comment !== '',
        ];
    }

    private function isAppendixTimelineChurn(object $review): bool
    {
        if ((string) ($review->get('section_key') ?? '') !== 'appendix_review') {
            return false;
        }

        return in_array((string) ($review->get('action_type') ?? ''), [
            'appendix_reviewed',
            'appendix_review_cleared',
            'appendix_reviewed_bulk',
            'appendix_review_cleared_bulk',
        ], true);
    }

    private function isWorkflowTimelineChurn(object $review): bool
    {
        if ((string) ($review->get('section_key') ?? '') !== 'workflow') {
            return false;
        }

        if ((string) ($review->get('field_path') ?? '') !== 'status') {
            return false;
        }

        return in_array((string) ($review->get('action_type') ?? ''), [
            'status_change',
            'system',
        ], true);
    }

    private function isIntakeTimelineChurn(object $review): bool
    {
        return (string) ($review->get('section_key') ?? '') === 'intake'
            && (string) ($review->get('action_type') ?? '') === 'system';
    }

    private function intakeTimelineGroupKey(object $review): string
    {
        return implode('|', [
            (string) ($review->get('section_key') ?? ''),
            (string) ($review->get('action_type') ?? ''),
            (string) ($review->get('field_path') ?? ''),
        ]);
    }

    /**
     * @param array<int, object> $reviews
     * @return array<string,mixed>
     */
    private function buildAppendixTimelineGroupView(array $reviews): array
    {
        $latest = $reviews[0];
        $summaries = [];
        foreach ($reviews as $review) {
            $summaries[] = [
                'title' => (string) ($review->label() ?? 'Appendix review activity'),
                'field_path' => trim((string) ($review->get('field_path') ?? '')),
                'comment' => (string) ($review->get('comment') ?? ''),
            ];
        }

        return [
            'kind' => 'appendix_group',
            'action_type' => 'appendix_review_group',
            'count' => count($reviews),
            'created_at' => (string) ($latest->get('created_at') ?? ''),
            'summaries' => $summaries,
        ];
    }

    /**
     * @param array<int, object> $reviews
     * @return array<string,mixed>
     */
    private function buildWorkflowTimelineGroupView(array $reviews): array
    {
        $latest = $reviews[0];
        $summaries = [];
        foreach ($reviews as $review) {
            $summaries[] = [
                'title' => (string) ($review->label() ?? 'Workflow activity'),
                'action_type' => (string) ($review->get('action_type') ?? ''),
                'comment' => (string) ($review->get('comment') ?? ''),
            ];
        }

        return [
            'kind' => 'workflow_group',
            'action_type' => 'workflow_group',
            'count' => count($reviews),
            'created_at' => (string) ($latest->get('created_at') ?? ''),
            'summaries' => $summaries,
        ];
    }

    /**
     * @param array<int, object> $reviews
     * @return array<string,mixed>
     */
    private function buildIntakeTimelineGroupView(array $reviews): array
    {
        $latest = $reviews[0];

        return [
            'kind' => 'intake_group',
            'action_type' => 'intake_group',
            'count' => count($reviews),
            'created_at' => (string) ($latest->get('created_at') ?? ''),
            'field_path' => (string) ($latest->get('field_path') ?? ''),
            'comment' => (string) ($latest->get('comment') ?? ''),
        ];
    }

    private function noticeKeyFromUri(string $uri): ?string
    {
        return match (true) {
            str_contains($uri, 'comment=added') => 'comment_added',
            str_contains($uri, 'status=updated') => 'status_updated',
            str_contains($uri, 'sent-back=intake') => 'sent_back',
            str_contains($uri, 'appendix=reviewed-all') => 'appendix_reviewed_all',
            str_contains($uri, 'appendix=cleared-all') => 'appendix_cleared_all',
            str_contains($uri, 'appendix=reviewed') => 'appendix_reviewed',
            str_contains($uri, 'appendix=cleared') => 'appendix_cleared',
            str_contains($uri, 'appendix-note=added') => 'appendix_note_added',
            str_contains($uri, 'appendix-note=cleared') => 'appendix_note_cleared',
            str_contains($uri, 'appendix-note=restored') => 'appendix_note_restored',
            str_contains($uri, 'error=comment') => 'error_comment',
            str_contains($uri, 'error=status') => 'error_status',
            str_contains($uri, 'error=appendix-review-required') => 'error_appendix_review_required',
            str_contains($uri, 'error=send-back') => 'error_send_back',
            str_contains($uri, 'error=appendix-note') => 'error_appendix_note',
            str_contains($uri, 'error=appendix') => 'error_appendix',
            default => null,
        };
    }

    private function appendixReviewWarning(object $submission): ?string
    {
        if ($this->reviewService->allAppendicesReviewed($submission)) {
            return null;
        }

        return $this->reviewService->pendingAppendixSummary($submission);
    }

    /**
     * @param array<string, array{reviewed:bool,reviewed_at:string,reviewer_uid:int}> $reviewedAppendices
     * @param array<string, array{comment:string,created_at:string,title:string}> $appendixNotes
     * @param array<string, array{action_type:string,created_at:string,title:string,comment:string}> $appendixNoteActivity
     * @param array<string, array{comment:string,created_at:string,title:string}> $recoverableAppendixNotes
     * @return list<array<string,mixed>>
     */
    private function buildAppendixReviewView(
        array $reviewedAppendices,
        array $appendixNotes,
        array $appendixNoteActivity,
        array $recoverableAppendixNotes,
        string $submissionId,
    ): array {
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
            $state = $reviewedAppendices[$appendix] ?? [
                'reviewed' => false,
                'reviewed_at' => '',
                'reviewer_uid' => 0,
            ];
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
            $recoverable = $recoverableAppendixNotes[$appendix] ?? [
                'comment' => '',
                'created_at' => '',
                'title' => '',
            ];
            $reviewed = (bool) ($state['reviewed'] ?? false);
            $commentRaw = (string) ($note['comment'] ?? '');
            $commentTrim = trim($commentRaw);

            $items[] = [
                'appendix' => $appendix,
                'label' => $label,
                'reviewed' => $reviewed,
                'status' => $reviewed ? 'Reviewed' : 'Not reviewed',
                'meta' => $reviewed && trim((string) ($state['reviewed_at'] ?? '')) !== ''
                    ? 'Checked at ' . (string) $state['reviewed_at']
                    : 'Waiting for staff signoff',
                'has_note' => $commentTrim !== '',
                'note_body_html' => $commentTrim !== '' ? nl2br(htmlspecialchars($commentRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : '',
                'note_meta' => $this->appendixNoteActivityMeta($note, $activity),
                'prefill_note' => $commentRaw,
                'show_restore' => $commentTrim === '' && trim((string) ($recoverable['comment'] ?? '')) !== '',
                'submission_id' => $submissionId,
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

        return 'No note activity yet.';
    }

    /**
     * @param array<string, mixed> $confidenceState
     * @return list<array<string,mixed>>
     */
    private function buildConfidenceView(array $confidenceState, string $submissionId): array
    {
        $items = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence']) || ($state['resolved'] ?? true) !== false) {
                continue;
            }

            $pathString = (string) $path;
            $items[] = [
                'path' => $pathString,
                'confidence' => number_format((float) $state['confidence'], 2),
                'updated_at' => (string) ($state['updated_at'] ?? 'n/a'),
                'note' => (string) ($state['note'] ?? 'No confidence note recorded.'),
                'data_link' => sprintf(
                    '/submissions/%s?edit_path=%s',
                    rawurlencode($submissionId),
                    rawurlencode($pathString),
                ),
                'comment_link' => sprintf(
                    '/submissions/%s/review?section_key=intake&field_path=%s&comment=%s',
                    rawurlencode($submissionId),
                    rawurlencode($pathString),
                    rawurlencode((string) ($state['note'] ?? '')),
                ),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $confidenceState
     */
    private function weakFieldSummary(array $confidenceState): string
    {
        $fields = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence']) || ($state['resolved'] ?? true) !== false) {
                continue;
            }

            $fields[] = sprintf('%s (%s)', (string) $path, (string) ($state['note'] ?? 'follow-up required'));
        }

        return implode(' · ', $fields);
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
}
