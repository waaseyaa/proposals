<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Review\ProposalReviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewController
{
    public function __construct(
        private readonly ProposalReviewService $reviewService,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->reviewService->loadSubmission($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $reviews = $this->reviewService->loadReviews($submissionId);
        $rawTimeline = isset($_GET['raw']) && (string) $_GET['raw'] === '1';
        $timeline = $rawTimeline
            ? implode('', array_map(fn (object $review): string => $this->renderReviewEntry($review), $reviews))
            : implode('', $this->renderTimelineEntries($reviews));
        if ($timeline === '') {
            $timeline = '<div class="empty">No staff review actions recorded yet.</div>';
        }

        $status = htmlspecialchars((string) ($submission->get('status') ?? 'draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $submissionIdEscaped = htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $notice = $this->noticeFromUri($_SERVER['REQUEST_URI'] ?? '');
        $prefillSection = htmlspecialchars(trim((string) ($_GET['section_key'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $prefillField = htmlspecialchars(trim((string) ($_GET['field_path'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $prefillComment = htmlspecialchars(trim((string) ($_GET['comment'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $workflowWarning = $this->renderWorkflowWarning(
            is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
        );
        $appendixChecklist = $this->renderAppendixChecklist(
            is_array($submission->get('completion_state')) ? $submission->get('completion_state') : [],
        );
        $appendixReviewPanel = $this->renderAppendixReviewPanel(
            $this->reviewService->reviewedAppendices($submission),
            $this->reviewService->latestAppendixNotes($submissionId),
            $this->reviewService->latestAppendixNoteActivity($submissionId),
            $this->reviewService->recoverableAppendixNotes($submissionId),
            (string) $submission->id(),
        );
        $appendixReviewWarning = $this->renderAppendixReviewWarning($submissionId);
        $confidencePanel = $this->renderConfidencePanel(
            is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [],
            (string) $submission->id(),
        );
        $researchBackedPanel = $this->renderAppliedResearchDraftsPanel(
            is_array($submission->get('validation_state')) ? $submission->get('validation_state') : [],
            (string) $submission->id(),
        );
        $timelineToggle = $this->renderTimelineToggle((string) $submission->id(), $rawTimeline);

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Review · Waaseyaa Proposals</title>
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
      max-width: 1180px;
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
    .status {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(49, 88, 69, 0.08);
      color: var(--moss);
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .notice {
      margin-top: 16px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(33, 102, 58, 0.18);
      background: rgba(33, 102, 58, 0.08);
      color: var(--success);
      font-weight: 700;
    }
    .warning {
      margin-top: 16px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(186, 90, 45, 0.28);
      background: rgba(186, 90, 45, 0.08);
      color: var(--ink);
    }
    .warning strong {
      display: block;
      margin-bottom: 6px;
      color: var(--rust);
    }
    .reopen {
      border: 1px solid rgba(186, 90, 45, 0.24);
      background: rgba(186, 90, 45, 0.06);
    }
    .layout {
      display: grid;
      grid-template-columns: 0.9fr 1.1fr;
      gap: 24px;
    }
    .stack {
      display: grid;
      gap: 18px;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 1.3rem;
    }
    form {
      display: grid;
      gap: 12px;
    }
    label {
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--muted);
    }
    input, select, textarea, button {
      font: inherit;
    }
    input, select, textarea {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: #fff;
      color: var(--ink);
    }
    textarea {
      min-height: 110px;
      resize: vertical;
    }
    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    button {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: var(--moss);
      color: #fff;
      cursor: pointer;
    }
    button.secondary {
      background: #fff;
      color: var(--moss);
    }
    .timeline {
      display: grid;
      gap: 14px;
    }
    .timeline-tools {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      margin-bottom: 12px;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .entry {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffefd;
    }
    .entry-header {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: baseline;
      margin-bottom: 8px;
    }
    .entry-title {
      font-weight: 700;
    }
    .entry-meta {
      color: var(--muted);
      font-size: 0.88rem;
    }
    .entry-type {
      display: inline-block;
      margin-bottom: 8px;
      padding: 4px 10px;
      border-radius: 999px;
      background: var(--card);
      color: var(--rust);
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-weight: 700;
    }
    .confidence-list {
      display: grid;
      gap: 12px;
    }
    .confidence-item {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
      background: #fffefd;
    }
    .confidence-item.weak {
      border-color: rgba(186, 90, 45, 0.4);
      background: #fff8f2;
    }
    .checklist {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
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
    .confidence-item strong {
      display: block;
      margin-bottom: 8px;
    }
    .confidence-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 8px;
      color: var(--muted);
      font-size: 0.88rem;
    }
    .risk {
      color: var(--rust);
      font-weight: 700;
    }
    .empty {
      border: 1px dashed var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffaf5;
      color: var(--muted);
    }
    @media (max-width: 960px) {
      .layout { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="eyebrow">Staff Review</div>
      <h1>__TITLE__</h1>
      <p>This cockpit is for Education Department and Economic Development review activity: capture comments, move the submission through revisions or approval, and preserve a visible audit trail.</p>
      <div class="status">__STATUS__</div>
      <div class="nav">
        <a href="/submissions">Back to submissions</a>
        <a href="/submissions/__ID__">Structured data</a>
        <a href="/submissions/__ID__/documents">Appendix documents</a>
        <a href="/submissions/__ID__/exports">Exports</a>
      </div>
      __NOTICE__
      __WORKFLOW_WARNING__
      __APPENDIX_REVIEW_WARNING__
    </section>
    <section class="layout">
      <div class="stack">
        <div class="card">
          <div class="eyebrow">Status Actions</div>
          <h2>Workflow</h2>
          <form method="post" action="/submissions/__ID__/review/status">
            <div>
              <label for="status">New Status</label>
              <select id="status" name="status">
                <option value="ready_for_review">ready_for_review</option>
                <option value="revisions_requested">revisions_requested</option>
                <option value="approved">approved</option>
                <option value="exported">exported</option>
                <option value="submitted">submitted</option>
              </select>
            </div>
            <div>
              <label for="status_note">Note</label>
              <textarea id="status_note" name="note" placeholder="Explain why the status is changing or what should happen next."></textarea>
            </div>
            <div class="actions">
              <button type="submit">Update Status</button>
            </div>
          </form>
        </div>
        <div class="card reopen">
          <div class="eyebrow">Send Back</div>
          <h2>Return To Intake</h2>
          <p>Use this when staff review finds issues that require applicant edits or another intake pass. A note is required and becomes part of the audit trail.</p>
          <form method="post" action="/submissions/__ID__/review/send-back">
            <div>
              <label for="send_back_note">Required Note</label>
              <textarea id="send_back_note" name="note" placeholder="Explain what needs to change before the submission can return to review."></textarea>
            </div>
            <div class="actions">
              <button type="submit">Send Back To Intake</button>
            </div>
          </form>
        </div>
        <div class="card">
          <div class="eyebrow">Reviewer Checkoff</div>
          <h2>Appendix Review Status</h2>
          <div class="actions">
            <form method="post" action="/submissions/__ID__/review/appendix/review-all">
              <button type="submit">Mark All Reviewed</button>
            </form>
            <form method="post" action="/submissions/__ID__/review/appendix/clear-all">
              <button type="submit" class="secondary">Clear All Reviews</button>
            </form>
          </div>
          __APPENDIX_REVIEW_PANEL__
        </div>
        <div class="card">
          <div class="eyebrow">Appendix Checklist</div>
          <h2>Package Completeness</h2>
          __APPENDIX_CHECKLIST__
        </div>
        <div class="card">
          <div class="eyebrow">Field Confidence</div>
          <h2>Intake Risk</h2>
          __CONFIDENCE_PANEL__
        </div>
        <div class="card">
          <div class="eyebrow">Research Provenance</div>
          <h2>Research-Backed Updates</h2>
          __RESEARCH_BACKED_PANEL__
        </div>
        <div class="card">
          <div class="eyebrow">Review Comment</div>
          <h2>Feedback</h2>
          <form method="post" action="/submissions/__ID__/review/comment">
            <div>
              <label for="section_key">Section</label>
              <input id="section_key" name="section_key" value="__PREFILL_SECTION__" placeholder="appendix_m, workflow, exports, career_plan">
            </div>
            <div>
              <label for="field_path">Field Path</label>
              <input id="field_path" name="field_path" value="__PREFILL_FIELD__" placeholder="business.operations.launch_timeline">
            </div>
            <div>
              <label for="comment">Comment</label>
              <textarea id="comment" name="comment" placeholder="Record reviewer feedback, revision requests, or approval notes.">__PREFILL_COMMENT__</textarea>
            </div>
            <div class="actions">
              <button type="submit">Add Comment</button>
            </div>
          </form>
        </div>
      </div>
      <div class="card">
        <div class="eyebrow">Audit Trail</div>
        <h2>Review Timeline</h2>
        __TIMELINE_TOGGLE__
        <div class="timeline">__TIMELINE__</div>
      </div>
    </section>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__STATUS__', '__ID__', '__NOTICE__', '__TIMELINE__', '__PREFILL_SECTION__', '__PREFILL_FIELD__', '__PREFILL_COMMENT__', '__CONFIDENCE_PANEL__', '__WORKFLOW_WARNING__', '__APPENDIX_CHECKLIST__', '__APPENDIX_REVIEW_PANEL__', '__APPENDIX_REVIEW_WARNING__', '__TIMELINE_TOGGLE__', '__RESEARCH_BACKED_PANEL__'],
            [$title, $status, $submissionIdEscaped, $notice, $timeline, $prefillSection, $prefillField, $prefillComment, $confidencePanel, $workflowWarning, $appendixChecklist, $appendixReviewPanel, $appendixReviewWarning, $timelineToggle, $researchBackedPanel],
            $html,
        );

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
     * @return list<string>
     */
    private function renderTimelineEntries(array $reviews): array
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

                $entries[] = $this->renderAppendixTimelineGroup($group);
                continue;
            }

            if ($this->isWorkflowTimelineChurn($review)) {
                $group = [];
                while ($index < $count && $this->isWorkflowTimelineChurn($reviews[$index])) {
                    $group[] = $reviews[$index];
                    $index++;
                }

                $entries[] = $this->renderWorkflowTimelineGroup($group);
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

                $entries[] = $this->renderIntakeTimelineGroup($group);
                continue;
            }

            if (!$this->isAppendixTimelineChurn($review)) {
                $entries[] = $this->renderReviewEntry($review);
                $index++;
                continue;
            }
        }

        return $entries;
    }

    private function renderReviewEntry(object $review): string
    {
        $title = htmlspecialchars((string) ($review->label() ?? 'Review Action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $actionType = htmlspecialchars((string) ($review->get('action_type') ?? 'review'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $comment = nl2br(htmlspecialchars((string) ($review->get('comment') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $sectionKey = htmlspecialchars((string) ($review->get('section_key') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fieldPath = htmlspecialchars((string) ($review->get('field_path') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $createdAt = htmlspecialchars((string) ($review->get('created_at') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $meta = trim(implode(' · ', array_filter([$sectionKey, $fieldPath, $createdAt])));

        return sprintf(
            '<article class="entry"><div class="entry-type">%s</div><div class="entry-header"><div class="entry-title">%s</div><div class="entry-meta">%s</div></div><div>%s</div></article>',
            $actionType,
            $title,
            $meta !== '' ? $meta : '&nbsp;',
            $comment !== '' ? $comment : '&nbsp;',
        );
    }

    private function renderTimelineToggle(string $submissionId, bool $rawTimeline): string
    {
        $href = $rawTimeline
            ? '/submissions/' . rawurlencode($submissionId) . '/review'
            : '/submissions/' . rawurlencode($submissionId) . '/review?raw=1';
        $label = $rawTimeline ? 'Show Compact Timeline' : 'Show Raw Audit Trail';
        $mode = $rawTimeline ? 'Raw audit mode' : 'Compact narrative mode';

        return sprintf(
            '<div class="timeline-tools"><span>%s</span><a href="%s">%s</a></div>',
            htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
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
     */
    private function renderAppendixTimelineGroup(array $reviews): string
    {
        $latest = $reviews[0] ?? null;
        if ($latest === null) {
            return '';
        }

        $createdAt = htmlspecialchars((string) ($latest->get('created_at') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaries = [];
        foreach ($reviews as $review) {
            $title = htmlspecialchars((string) ($review->label() ?? 'Appendix review activity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $comment = htmlspecialchars((string) ($review->get('comment') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fieldPath = trim((string) ($review->get('field_path') ?? ''));
            $fieldMeta = $fieldPath !== '' ? ' · ' . htmlspecialchars($fieldPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            $summaries[] = sprintf('<div><strong>%s</strong>%s%s</div>', $title, $fieldMeta, $comment !== '' ? ' : ' . $comment : '');
        }

        return sprintf(
            '<article class="entry"><div class="entry-type">appendix_review_group</div><div class="entry-header"><div class="entry-title">Appendix review activity (%d entries)</div><div class="entry-meta">%s</div></div><div>%s</div></article>',
            count($reviews),
            $createdAt !== '' ? $createdAt : '&nbsp;',
            implode('', $summaries),
        );
    }

    /**
     * @param array<int, object> $reviews
     */
    private function renderWorkflowTimelineGroup(array $reviews): string
    {
        $latest = $reviews[0] ?? null;
        if ($latest === null) {
            return '';
        }

        $createdAt = htmlspecialchars((string) ($latest->get('created_at') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaries = [];
        foreach ($reviews as $review) {
            $title = htmlspecialchars((string) ($review->label() ?? 'Workflow activity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $comment = htmlspecialchars((string) ($review->get('comment') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $actionType = htmlspecialchars((string) ($review->get('action_type') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summaries[] = sprintf('<div><strong>%s</strong> · %s%s</div>', $title, $actionType !== '' ? $actionType : 'workflow', $comment !== '' ? ' : ' . $comment : '');
        }

        return sprintf(
            '<article class="entry"><div class="entry-type">workflow_group</div><div class="entry-header"><div class="entry-title">Workflow activity (%d entries)</div><div class="entry-meta">%s</div></div><div>%s</div></article>',
            count($reviews),
            $createdAt !== '' ? $createdAt : '&nbsp;',
            implode('', $summaries),
        );
    }

    /**
     * @param array<int, object> $reviews
     */
    private function renderIntakeTimelineGroup(array $reviews): string
    {
        $latest = $reviews[0] ?? null;
        if ($latest === null) {
            return '';
        }

        $createdAt = htmlspecialchars((string) ($latest->get('created_at') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fieldPath = htmlspecialchars((string) ($latest->get('field_path') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $comment = htmlspecialchars((string) ($latest->get('comment') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<article class="entry"><div class="entry-type">intake_group</div><div class="entry-header"><div class="entry-title">Intake activity (%d entries)</div><div class="entry-meta">%s</div></div><div><strong>%s</strong>%s</div></article>',
            count($reviews),
            $createdAt !== '' ? $createdAt : '&nbsp;',
            $fieldPath !== '' ? $fieldPath : 'intake',
            $comment !== '' ? ' : ' . $comment : '',
        );
    }

    private function noticeFromUri(string $uri): string
    {
        return match (true) {
            str_contains($uri, 'comment=added') => '<div class="notice">Review comment saved.</div>',
            str_contains($uri, 'status=updated') => '<div class="notice">Submission status updated and logged.</div>',
            str_contains($uri, 'sent-back=intake') => '<div class="notice">Submission returned to intake and logged for follow-up.</div>',
            str_contains($uri, 'appendix=reviewed') => '<div class="notice">Appendix marked reviewed.</div>',
            str_contains($uri, 'appendix=cleared') => '<div class="notice">Appendix review state cleared.</div>',
            str_contains($uri, 'appendix=reviewed-all') => '<div class="notice">All appendices marked reviewed.</div>',
            str_contains($uri, 'appendix=cleared-all') => '<div class="notice">All appendix review states cleared.</div>',
            str_contains($uri, 'appendix-note=added') => '<div class="notice">Appendix review note saved.</div>',
            str_contains($uri, 'appendix-note=cleared') => '<div class="notice">Appendix review note cleared.</div>',
            str_contains($uri, 'appendix-note=restored') => '<div class="notice">Appendix review note restored from the latest saved version.</div>',
            str_contains($uri, 'error=comment') => '<div class="notice">Unable to save the comment. Check the form and try again.</div>',
            str_contains($uri, 'error=status') => '<div class="notice">Unable to update the status. Check the form and try again.</div>',
            str_contains($uri, 'error=appendix-review-required') => '<div class="notice">Approval, export, and submission require all six appendices to be staff-reviewed first.</div>',
            str_contains($uri, 'error=send-back') => '<div class="notice">A note is required to send the submission back to intake.</div>',
            str_contains($uri, 'error=appendix-note') => '<div class="notice">Unable to save the appendix review note.</div>',
            str_contains($uri, 'error=appendix') => '<div class="notice">Unable to update appendix review status.</div>',
            default => '',
        };
    }

    private function renderAppendixReviewWarning(int|string $submissionId): string
    {
        $submission = $this->reviewService->loadSubmission($submissionId);
        if ($submission === null || $this->reviewService->allAppendicesReviewed($submission)) {
            return '';
        }

        return sprintf(
            '<div class="warning"><strong>Review gate</strong><div>Approval, export, and submission are blocked until every appendix is staff-reviewed. Remaining: %s.</div></div>',
            htmlspecialchars($this->reviewService->pendingAppendixSummary($submission), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param array<string, array{reviewed:bool,reviewed_at:string,reviewer_uid:int}> $reviewedAppendices
     * @param array<string, array{comment:string,created_at:string,title:string}> $appendixNotes
     * @param array<string, array{action_type:string,created_at:string,title:string,comment:string}> $appendixNoteActivity
     * @param array<string, array{comment:string,created_at:string,title:string}> $recoverableAppendixNotes
     */
    private function renderAppendixReviewPanel(array $reviewedAppendices, array $appendixNotes, array $appendixNoteActivity, array $recoverableAppendixNotes, string $submissionId): string
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
            $recoverableNote = $recoverableAppendixNotes[$appendix] ?? [
                'comment' => '',
                'created_at' => '',
                'title' => '',
            ];
            $reviewed = (bool) ($state['reviewed'] ?? false);
            $status = $reviewed ? 'Reviewed' : 'Not reviewed';
            $meta = $reviewed && trim((string) ($state['reviewed_at'] ?? '')) !== ''
                ? 'Checked at ' . htmlspecialchars((string) $state['reviewed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : 'Waiting for staff signoff';
            $notePanel = trim((string) $note['comment']) !== ''
                ? sprintf(
                    '<div class="annotation-value"><strong>Latest note</strong><div>%s</div><div class="entry-meta">%s</div></div>',
                    nl2br(htmlspecialchars((string) $note['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                    $this->renderAppendixNoteActivityMeta($note, $activity),
                )
                : sprintf(
                    '<div class="entry-meta">No appendix-specific review note yet. %s</div>',
                    $this->renderAppendixNoteActivityMeta($note, $activity),
                );
            $prefillNote = htmlspecialchars((string) ($note['comment'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $clearNoteButton = trim((string) ($note['comment'] ?? '')) !== ''
                ? sprintf(
                    '<form method="post" action="/submissions/%s/review/appendix/note/clear"><input type="hidden" name="appendix" value="%s"><button type="submit" class="secondary">Clear Note</button></form>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                )
                : '';
            $restoreNoteButton = trim((string) ($note['comment'] ?? '')) === '' && trim((string) ($recoverableNote['comment'] ?? '')) !== ''
                ? sprintf(
                    '<form method="post" action="/submissions/%s/review/appendix/note/restore"><input type="hidden" name="appendix" value="%s"><button type="submit" class="secondary">Restore Last Note</button></form>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                )
                : '';
            $button = $reviewed
                ? sprintf(
                    '<form method="post" action="/submissions/%s/review/appendix/clear"><input type="hidden" name="appendix" value="%s"><button type="submit" class="secondary">Clear Review</button></form>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                )
                : sprintf(
                    '<form method="post" action="/submissions/%s/review/appendix/review"><input type="hidden" name="appendix" value="%s"><button type="submit">Mark Reviewed</button></form>',
                    htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            $noteForm = sprintf(
                '<form method="post" action="/submissions/%s/review/appendix/note"><input type="hidden" name="appendix" value="%s"><label for="appendix_note_%s">Review Note</label><textarea id="appendix_note_%s" name="comment" placeholder="Record appendix-specific feedback or signoff context.">%s</textarea><button type="submit" class="secondary">Save Note</button></form>',
                htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($appendix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $prefillNote,
            );

            $items[] = sprintf(
                '<div class="checklist-item"><strong>%s</strong>%s<div class="entry-meta">%s</div>%s<div class="actions">%s%s</div>%s</div>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $meta,
                $notePanel,
                $button,
                $clearNoteButton . $restoreNoteButton,
                $noteForm,
            );
        }

        return '<div class="checklist">' . implode('', $items) . '</div>';
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

        return 'No note activity yet.';
    }

    /**
     * @param array<string, mixed> $confidenceState
     */
    private function renderConfidencePanel(array $confidenceState, string $submissionId): string
    {
        $items = [];

        foreach ($confidenceState as $path => $state) {
            if (!is_array($state) || !isset($state['confidence']) || ($state['resolved'] ?? true) !== false) {
                continue;
            }

            $commentLink = sprintf(
                '/submissions/%s/review?section_key=intake&field_path=%s&comment=%s',
                rawurlencode($submissionId),
                rawurlencode((string) $path),
                rawurlencode((string) ($state['note'] ?? '')),
            );
            $dataLink = sprintf(
                '/submissions/%s?edit_path=%s',
                rawurlencode($submissionId),
                rawurlencode((string) $path),
            );

            $items[] = sprintf(
                '<article class="confidence-item weak"><strong>%s</strong><div class="confidence-meta"><span>confidence %.2f</span><span class="risk">follow-up needed</span><span>%s</span></div><div>%s</div><p><a href="%s">Open in structured data</a> · <a href="%s">Comment with prefilled field</a></p></article>',
                htmlspecialchars((string) $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (float) $state['confidence'],
                htmlspecialchars((string) ($state['updated_at'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($state['note'] ?? 'No confidence note recorded.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($dataLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($commentLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        if ($items === []) {
            return '<div class="empty">No unresolved low-confidence intake fields are recorded right now.</div>';
        }

        return '<div class="confidence-list">' . implode('', $items) . '</div>';
    }

    /**
     * @param array<string, mixed> $confidenceState
     */
    private function renderWorkflowWarning(array $confidenceState): string
    {
        $summary = $this->weakFieldSummary($confidenceState);

        if ($summary === '') {
            return '';
        }

        return sprintf(
            '<div class="warning"><strong>Approval warning</strong><div>Low-confidence intake fields are still open: %s</div></div>',
            htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
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
                '<article class="confidence-item"><strong>%s</strong><div class="confidence-meta"><span>provider: %s</span><span>quality: %s</span><span>applied: %s</span><span><a href="/submissions/%s?edit_path=%s">Inspect field</a></span></div><div>%s</div></article>',
                htmlspecialchars((string) ($draft['target_path'] ?? 'draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['source_provider'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['draft_quality'] ?? 'unrated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($draft['applied_at'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($submissionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                rawurlencode((string) ($draft['target_path'] ?? '')),
                htmlspecialchars((string) ($draft['source_query'] ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        if ($items === []) {
            return '<div class="empty">No active research-backed field updates are currently applied.</div>';
        }

        return '<div class="confidence-list">' . implode('', $items) . '</div>';
    }
}
