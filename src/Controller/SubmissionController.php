<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class SubmissionController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
        private readonly Environment $twig,
    ) {}

    public function index(): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $ids = $storage->getQuery()
            ->sort('id', 'ASC')
            ->execute();
        $submissions = $storage->loadMultiple($ids);

        $items = [];
        foreach ($submissions as $submission) {
            $items[] = $this->buildListItemView($submission);
        }

        $html = $this->twig->render('pages/submissions/index.html.twig', [
            'items' => $items,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function show(int|string $submissionId): Response
    {
        $storage = $this->entityTypeManager->getStorage('proposal_submission');
        $submission = $storage->load($submissionId);

        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $submissionIdString = (string) $submission->id();
        $canonicalData = is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [];
        $validationState = is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [];
        $confidenceState = is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [];
        $completionState = is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [];
        $researchLog = is_array($submission->get('research_log')) ? $submission->get('research_log') : [];

        $reviewSummary = $this->reviewService->summarizeSubmission($submission);
        $readinessSummary = $this->buildReadinessSummary($submission);
        $artifactAudit = $this->artifactAuditService->summarize($submission);
        $appendixNotes = $this->reviewService->latestAppendixNotes($submission->id());
        $appendixNoteActivity = $this->reviewService->latestAppendixNoteActivity($submission->id());

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        $html = $this->twig->render('pages/submissions/show.html.twig', [
            'title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => $submissionIdString,
            'status' => (string) ($submission->get('status') ?? 'unknown'),
            'applicant' => (string) ($submission->get('applicant_name') ?? ''),
            'business' => (string) ($submission->get('business_name') ?? ''),
            'canonical_json' => (string) json_encode($canonicalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'source_json' => (string) json_encode($submission->get('source_form_data') ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'review' => $this->buildReviewPanelView($reviewSummary),
            'readiness' => $readinessSummary,
            'readiness_action' => $this->buildReadinessActionView($readinessSummary, $submissionIdString, $requestUri),
            'completion_state' => $completionState,
            'artifact_audit' => $this->buildArtifactAuditPanelView($artifactAudit, $submissionIdString),
            'research' => $this->buildResearchPanelView($researchLog, $validationState, $submissionIdString),
            'research_backed' => $this->buildAppliedResearchDraftsView($validationState, $submissionIdString),
            'appendix_notes' => $this->buildAppendixNotesView($appendixNotes, $appendixNoteActivity),
            'confidence_items' => $this->buildConfidencePanelView($confidenceState, $canonicalData),
            'edit_panel' => $this->buildCanonicalEditPanelView(
                $canonicalData,
                $submissionIdString,
                (string) ($_GET['edit_path'] ?? ''),
                (string) ($_GET['edit_format'] ?? 'string'),
                $this->editNoticeKey($requestUri),
            ),
            'field_review' => $this->buildFieldReviewView(
                $this->reviewService->fieldAnnotations($submission->id()),
                $canonicalData,
                $submissionIdString,
            ),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
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

    /**
     * @return array<string,mixed>
     */
    private function buildListItemView(EntityInterface $submission): array
    {
        $reviewSummary = $this->reviewService->summarizeSubmission($submission);

        $cohort = null;
        $cohortId = (int) ($submission->get('cohort_id') ?? 0);
        if ($cohortId > 0) {
            $cohortEntity = $this->entityTypeManager->getStorage('proposal_cohort')->load($cohortId);
            if ($cohortEntity instanceof EntityInterface) {
                $cohort = [
                    'id' => (string) $cohortEntity->id(),
                    'label' => (string) ($cohortEntity->label() ?? 'Cohort'),
                ];
            }
        }

        $confidenceSummary = $this->summarizeConfidenceState(is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : []);
        $readinessSummary = $this->buildReadinessSummary($submission);

        $reviewMeta = sprintf(
            'Reviews: %d · Reviewed appendices: %d/%d%s',
            (int) $reviewSummary['review_count'],
            (int) $reviewSummary['reviewed_appendix_count'],
            (int) $reviewSummary['reviewed_appendix_total'],
            ($reviewSummary['latest_created_at'] ?? null) ? ' · Latest: ' . (string) $reviewSummary['latest_created_at'] : '',
        );

        $revisionNote = null;
        if (($reviewSummary['latest_comment'] ?? null) && $reviewSummary['has_revisions_requested']) {
            $revisionNote = (string) $reviewSummary['latest_comment'];
        }

        $weakCount = (int) $confidenceSummary['weak_count'];

        return [
            'id' => (string) $submission->id(),
            'url' => '/submissions/' . rawurlencode((string) $submission->id()),
            'title' => (string) ($submission->label() ?? 'Untitled Submission'),
            'status' => (string) ($submission->get('status') ?? 'unknown'),
            'applicant' => (string) ($submission->get('applicant_name') ?? ''),
            'business' => (string) ($submission->get('business_name') ?? ''),
            'cohort' => $cohort,
            'review_meta' => $reviewMeta,
            'revision_note' => $revisionNote,
            'weak_count' => $weakCount,
            'weak_single' => $weakCount === 1,
            'readiness_label' => (string) $readinessSummary['label'],
        ];
    }

    /**
     * @param array{review_count:int,latest_action:?string,latest_title:?string,latest_comment:?string,latest_section:?string,latest_field:?string,latest_created_at:?string,status:string,current_step:string,has_revisions_requested:bool,is_approved:bool,reviewed_appendix_count:int,reviewed_appendix_total:int} $reviewSummary
     * @return array<string,mixed>
     */
    private function buildReviewPanelView(array $reviewSummary): array
    {
        $latestComment = trim((string) ($reviewSummary['latest_comment'] ?? ''));
        $latestSection = trim((string) ($reviewSummary['latest_section'] ?? ''));
        $latestField = trim((string) ($reviewSummary['latest_field'] ?? ''));
        $latestCreatedAt = trim((string) ($reviewSummary['latest_created_at'] ?? ''));

        $revisionPill = null;
        if ($reviewSummary['has_revisions_requested']) {
            $revisionPill = 'Revisions Requested';
        } elseif ($reviewSummary['is_approved']) {
            $revisionPill = 'Approved Track';
        }

        return [
            'status' => (string) $reviewSummary['status'],
            'current_step' => (string) $reviewSummary['current_step'],
            'review_count' => (int) $reviewSummary['review_count'],
            'reviewed_appendix_count' => (int) $reviewSummary['reviewed_appendix_count'],
            'reviewed_appendix_total' => (int) $reviewSummary['reviewed_appendix_total'],
            'latest_section' => $latestSection !== '' ? $latestSection : 'n/a',
            'latest_field' => $latestField !== '' ? $latestField : 'n/a',
            'latest_created_at' => $latestCreatedAt !== '' ? $latestCreatedAt : 'n/a',
            'revision_pill' => $revisionPill,
            'has_note' => $latestComment !== '',
            'latest_title' => trim((string) ($reviewSummary['latest_title'] ?? 'Latest review activity')),
            'latest_comment' => $latestComment,
        ];
    }

    /**
     * @param array{ready_count:int,total_count:int,missing:list<string>,items:list<array{document_type:string,label:string,ready:bool}>} $artifactAudit
     * @return array<string,mixed>
     */
    private function buildArtifactAuditPanelView(array $artifactAudit, string $submissionId): array
    {
        return [
            'ready_count' => (int) $artifactAudit['ready_count'],
            'total_count' => (int) $artifactAudit['total_count'],
            'missing_count' => count($artifactAudit['missing']),
            'missing' => $artifactAudit['missing'],
            'items' => array_map(
                static fn (array $item): array => [
                    'label' => (string) $item['label'],
                    'ready' => (bool) $item['ready'],
                ],
                $artifactAudit['items'],
            ),
            'submission_id' => $submissionId,
        ];
    }

    /**
     * @param array<string, mixed> $confidenceState
     * @param array<string, mixed> $canonicalData
     * @return list<array<string,mixed>>
     */
    private function buildConfidencePanelView(array $confidenceState, array $canonicalData): array
    {
        $items = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence'])) {
                continue;
            }

            $pathString = (string) $path;
            $resolved = (bool) ($state['resolved'] ?? true);
            $note = (string) ($state['note'] ?? '');
            $updatedAt = (string) ($state['updated_at'] ?? '');
            $items[] = [
                'path' => $pathString,
                'resolved' => $resolved,
                'confidence' => sprintf('%.2f', (float) $state['confidence']),
                'state_label' => $resolved ? 'resolved' : 'follow-up needed',
                'updated_at' => $updatedAt !== '' ? $updatedAt : 'n/a',
                'note' => $note !== '' ? $note : 'No confidence note recorded.',
                'value' => $this->renderAnnotationValue($this->valueAtPath($canonicalData, $pathString)),
            ];
        }

        return $items;
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
     * @return array{show_form:bool,notice:?string}
     */
    private function buildReadinessActionView(array $summary, string $submissionId, string $uri): array
    {
        $notice = match (true) {
            str_contains($uri, 'status=ready-for-review') => 'moved_to_ready',
            str_contains($uri, 'error=not-ready') => 'not_ready',
            default => null,
        };

        $showForm = $summary['label'] === 'Ready for review' && $summary['status'] === 'intake_in_progress';

        return [
            'show_form' => $showForm,
            'notice' => $notice,
        ];
    }

    /**
     * @param list<array<string,mixed>> $researchLog
     * @param array<string,mixed> $validationState
     * @return array{drafts:list<array<string,mixed>>,drafts_notice:?string,items:list<array<string,mixed>>}
     */
    private function buildResearchPanelView(array $researchLog, array $validationState, string $submissionId): array
    {
        $items = [];
        foreach (array_reverse($researchLog, true) as $index => $item) {
            if (!is_array($item) || !isset($item['query'])) {
                continue;
            }

            $assessment = $this->assessResearchItem($item);
            $citations = [];
            foreach (array_slice(is_array($item['citations'] ?? null) ? $item['citations'] : [], 0, 3) as $citation) {
                if (!is_array($citation)) {
                    continue;
                }
                $url = (string) ($citation['url'] ?? '#');
                $citations[] = [
                    'is_internal' => str_starts_with((string) ($citation['url'] ?? ''), '/'),
                    'title' => (string) ($citation['title'] ?? 'Source'),
                    'url' => $url,
                ];
            }

            $targetOptions = $assessment['draftable']
                ? $this->buildResearchDraftTargetOptionsView((string) ($item['kind'] ?? 'research'))
                : [];

            $items[] = [
                'index' => (int) $index,
                'kind' => (string) ($item['kind'] ?? 'research'),
                'query' => (string) ($item['query'] ?? ''),
                'provider' => (string) ($item['provider'] ?? 'research'),
                'summary' => (string) ($item['summary'] ?? 'No summary available.'),
                'quality_label' => (string) $assessment['label'],
                'citations' => $citations,
                'draftable' => (bool) $assessment['draftable'],
                'lock_reason' => (string) $assessment['reason'],
                'target_options' => $targetOptions,
                'submission_id' => $submissionId,
            ];

            if (count($items) >= 4) {
                break;
            }
        }

        return [
            'drafts' => $this->buildResearchDraftsView(
                is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [],
                $submissionId,
            ),
            'drafts_notice' => $this->researchDraftsNoticeKey($_SERVER['REQUEST_URI'] ?? ''),
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string,mixed>> $drafts
     * @return list<array<string,mixed>>
     */
    private function buildResearchDraftsView(array $drafts, string $submissionId): array
    {
        $items = [];

        foreach (array_reverse($drafts) as $draft) {
            if (!is_array($draft)) {
                continue;
            }

            $status = (string) ($draft['status'] ?? 'pending');
            $targetPathRaw = (string) ($draft['target_path'] ?? '');
            $sourceQuery = (string) ($draft['source_query'] ?? '');
            $suggestedValue = (string) ($draft['suggested_value'] ?? '');

            $items[] = [
                'id' => (string) ($draft['id'] ?? ''),
                'status' => $status,
                'target_path' => $targetPathRaw !== '' ? $targetPathRaw : 'draft',
                'target_path_encoded' => rawurlencode($targetPathRaw),
                'source_kind' => (string) ($draft['source_kind'] ?? 'research'),
                'quality' => (string) ($draft['draft_quality'] ?? 'unrated'),
                'created_at' => (string) ($draft['created_at'] ?? 'n/a'),
                'source_query' => $sourceQuery !== '' ? $sourceQuery : 'n/a',
                'suggested_value' => $suggestedValue !== '' ? $suggestedValue : 'No suggested value available.',
                'can_apply' => $status === 'pending',
                'can_restore' => $status === 'applied' && array_key_exists('previous_value', $draft),
                'submission_id' => $submissionId,
            ];
        }

        return $items;
    }

    private function researchDraftsNoticeKey(string $uri): ?string
    {
        return match (true) {
            str_contains($uri, 'updated=research-draft-existing') => 'research_draft_existing',
            str_contains($uri, 'updated=research-draft') => 'research_draft',
            str_contains($uri, 'updated=research-applied') => 'research_applied',
            str_contains($uri, 'updated=research-rejected') => 'research_rejected',
            str_contains($uri, 'updated=research-restored') => 'research_restored',
            str_contains($uri, 'error=research-draft-ungrounded') => 'error_research_ungrounded',
            str_contains($uri, 'error=research-draft') => 'error_research_draft',
            str_contains($uri, 'error=research-apply') => 'error_research_apply',
            str_contains($uri, 'error=research-restore') => 'error_research_restore',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $validationState
     * @return list<array<string,string>>
     */
    private function buildAppliedResearchDraftsView(array $validationState, string $submissionId): array
    {
        $drafts = is_array($validationState['research_drafts'] ?? null) ? $validationState['research_drafts'] : [];
        $items = [];

        foreach (array_reverse($drafts) as $draft) {
            if (!is_array($draft) || (string) ($draft['status'] ?? '') !== 'applied') {
                continue;
            }

            $targetPathRaw = (string) ($draft['target_path'] ?? 'draft');
            $sourceQuery = (string) ($draft['source_query'] ?? '');
            $suggestedValue = (string) ($draft['suggested_value'] ?? '');

            $items[] = [
                'target_path' => $targetPathRaw,
                'target_path_encoded' => rawurlencode((string) ($draft['target_path'] ?? '')),
                'provider' => (string) ($draft['source_provider'] ?? 'research'),
                'quality' => (string) ($draft['draft_quality'] ?? 'unrated'),
                'applied_at' => (string) ($draft['applied_at'] ?? 'n/a'),
                'source_query' => $sourceQuery !== '' ? $sourceQuery : 'n/a',
                'suggested_value' => $suggestedValue !== '' ? $suggestedValue : 'No applied value stored.',
                'submission_id' => $submissionId,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{value:string,label:string,selected:bool}>
     */
    private function buildResearchDraftTargetOptionsView(string $kind): array
    {
        $preferred = match ($kind) {
            'costing' => 'funding_request.support_rationale',
            'market_validation' => 'business.market.marketing_plan',
            default => 'career_plan.three_year_plan',
        };

        $options = [];
        foreach ($this->researchDraftTargetOptions() as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label . ' · ' . $value,
                'selected' => $value === $preferred,
            ];
        }
        return $options;
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

            $commentRaw = (string) $note['comment'];
            $body = trim($commentRaw) !== ''
                ? nl2br(htmlspecialchars($commentRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                : 'No appendix-specific review note recorded.';

            $items[] = [
                'label' => $label,
                'body' => $body,
                'body_is_raw' => trim($commentRaw) !== '',
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

    /**
     * @param list<array{section_key:string,field_path:string,comment:string,action_type:string,created_at:string,title:string}> $annotations
     * @param array<string, mixed> $canonicalData
     * @return list<array<string,string>>
     */
    private function buildFieldReviewView(array $annotations, array $canonicalData, string $submissionId): array
    {
        $items = [];
        foreach ($annotations as $annotation) {
            $items[] = [
                'field_path' => (string) $annotation['field_path'],
                'section_key' => $annotation['section_key'] !== '' ? (string) $annotation['section_key'] : 'n/a',
                'action_type' => (string) $annotation['action_type'],
                'created_at' => (string) $annotation['created_at'],
                'comment' => (string) $annotation['comment'],
                'review_url' => sprintf(
                    '/submissions/%s/review?section_key=%s&field_path=%s',
                    rawurlencode($submissionId),
                    rawurlencode($annotation['section_key']),
                    rawurlencode($annotation['field_path']),
                ),
                'edit_url' => sprintf(
                    '/submissions/%s?edit_path=%s',
                    rawurlencode($submissionId),
                    rawurlencode($annotation['field_path']),
                ),
                'value' => $this->renderAnnotationValue($this->valueAtPath($canonicalData, (string) $annotation['field_path'])),
            ];
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $canonicalData
     * @return array<string,mixed>
     */
    private function buildCanonicalEditPanelView(
        array $canonicalData,
        string $submissionId,
        string $requestedPath,
        string $requestedFormat,
        ?string $noticeKey,
    ): array {
        $fieldPath = trim($requestedPath);
        $currentValue = $fieldPath !== '' ? $this->valueAtPath($canonicalData, $fieldPath) : null;
        $renderedValue = $fieldPath !== '' ? $this->renderAnnotationValue($currentValue) : '';

        return [
            'notice' => $noticeKey,
            'field_path' => $fieldPath,
            'format' => $requestedFormat,
            'rendered_value' => $renderedValue,
            'display_value' => $fieldPath !== '' ? $renderedValue : 'Choose a canonical path to inspect or update.',
        ];
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

    private function editNoticeKey(string $uri): ?string
    {
        return match (true) {
            str_contains($uri, 'updated=canonical') => 'updated',
            str_contains($uri, 'error=canonical') => 'error',
            default => null,
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
