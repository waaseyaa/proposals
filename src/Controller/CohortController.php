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
use Waaseyaa\Entity\EntityInterface;

final class CohortController
{
    public function __construct(
        private readonly CohortOverviewService $cohortOverview,
        private readonly CohortBundleService $cohortBundleService,
        private readonly ArtifactAuditService $artifactAuditService,
        private readonly ProposalReviewService $reviewService,
    ) {}

    public function index(): Response
    {
        $cohorts = $this->cohortOverview->loadCohorts();
        $cards = '';

        foreach ($cohorts as $cohort) {
            $cards .= $this->renderCohortCard($cohort);
        }

        if ($cards === '') {
            $cards = '<div class="empty">No cohorts exist yet. Run <code>php bin/waaseyaa proposals:seed-northops</code> to seed the pilot cohort.</div>';
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cohorts · Waaseyaa Proposals</title>
  <style>
    :root {
      --ink: #171411;
      --paper: #fffdfa;
      --line: #dbccb8;
      --moss: #315845;
      --rust: #ba5a2d;
      --muted: #6f665c;
    }
    body {
      margin: 0;
      font-family: Georgia, serif;
      color: var(--ink);
      background: linear-gradient(180deg, #f8f3eb 0%, #efe5d8 100%);
    }
    main {
      max-width: 1120px;
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
    .grid, .metrics {
      display: grid;
      gap: 18px;
    }
    .grid { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    .metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 14px; }
    .metric {
      background: #fffaf2;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
    }
    .metric strong {
      display: block;
      font-size: 1.5rem;
      color: var(--moss);
    }
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
    h2 {
      margin: 10px 0 8px;
      font-size: 1.4rem;
    }
    p { line-height: 1.8; color: var(--muted); }
    .meta, .links {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .meta { font-size: 0.92rem; color: var(--muted); }
    .empty {
      border: 1px dashed var(--line);
      border-radius: 14px;
      padding: 20px;
      background: #fffaf5;
    }
    @media (max-width: 760px) {
      .metrics { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="eyebrow">Cohort Review Board</div>
      <h1>Staff can now see proposal progress at cohort level.</h1>
      <p>This surface rolls up readiness, weak-field risk, appendix signoff, approval state, and export state across all submissions attached to a cohort.</p>
      <p><a href="/">Dashboard</a> · <a href="/submissions">Submissions</a></p>
    </section>
    <section class="grid">__CARDS__</section>
  </main>
</body>
</html>
HTML;

        return new Response(str_replace('__CARDS__', $cards, $html), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function show(int|string $cohortId): Response
    {
        $cohort = null;
        foreach ($this->cohortOverview->loadCohorts() as $loadedCohort) {
            if ((string) $loadedCohort->id() === (string) $cohortId) {
                $cohort = $loadedCohort;
                break;
            }
        }

        if (!$cohort instanceof EntityInterface) {
            return new Response('Cohort not found.', 404);
        }

        $summary = $this->cohortOverview->summarizeCohort($cohort);
        $submissions = $this->cohortOverview->loadSubmissionsForCohort($cohort->id());
        $rows = '';
        $attentionItems = '';

        foreach ($submissions as $submission) {
            $rows .= $this->renderSubmissionRow($submission);
            $attentionItems .= $this->renderAttentionItem($submission);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No submissions are attached to this cohort yet.</td></tr>';
        }
        if ($attentionItems === '') {
            $attentionItems = '<div class="empty">No active blockers. This cohort has no unresolved or weak-field follow-up queued right now.</div>';
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cohort Detail · Waaseyaa Proposals</title>
  <style>
    :root {
      --ink: #171411;
      --paper: #fffdfa;
      --line: #dbccb8;
      --moss: #315845;
      --rust: #ba5a2d;
      --muted: #6f665c;
      --card: #f7efe3;
    }
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
    .metrics {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 14px;
      margin-top: 16px;
    }
    .metric {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 14px;
    }
    .metric strong {
      display: block;
      font-size: 1.45rem;
      color: var(--moss);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    th, td {
      padding: 12px 10px;
      border-top: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
    }
    th {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      background: var(--card);
    }
    .links {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .links a {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: #fff;
      text-decoration: none;
      font-size: 0.88rem;
    }
    @media (max-width: 960px) {
      .metrics { grid-template-columns: 1fr 1fr; }
      table, thead, tbody, tr, th, td { display: block; }
      thead { display: none; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="eyebrow">Cohort Detail</div>
      <h1>__TITLE__</h1>
      <p>This board gives staff one place to monitor proposal progress, weak-field risk, appendix review completion, and export readiness across the cohort.</p>
      <p><a href="/cohorts">All cohorts</a> · <a href="/submissions">All submissions</a></p>
      <p><a href="/cohorts/__ID__/export.csv">Download cohort CSV</a> · <a href="/cohorts/__ID__/bundle/download">Download cohort bundle</a></p>
      <div class="metrics">
        <div class="metric"><strong>__TOTAL__</strong><span>Submissions</span></div>
        <div class="metric"><strong>__READY__</strong><span>Ready for review</span></div>
        <div class="metric"><strong>__APPROVED__</strong><span>Approved track</span></div>
        <div class="metric"><strong>__EXPORTED__</strong><span>Exported</span></div>
        <div class="metric"><strong>__WEAK__</strong><span>Weak fields open</span></div>
      </div>
    </section>
    <section class="card">
      <div class="eyebrow">Attention Queue</div>
      __ATTENTION_ITEMS__
    </section>
    <section class="card">
      <div class="eyebrow">Participant Board</div>
      <table>
        <thead>
          <tr>
            <th>Submission</th>
            <th>Status</th>
            <th>Readiness</th>
            <th>Review</th>
            <th>Risk</th>
            <th>Artifacts</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>__ROWS__</tbody>
      </table>
    </section>
  </main>
</body>
</html>
HTML;

        $replacements = [
            '__TITLE__' => htmlspecialchars((string) ($cohort->label() ?? 'Cohort'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__ID__' => htmlspecialchars((string) $cohort->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '__TOTAL__' => (string) $summary['total'],
            '__READY__' => (string) $summary['ready_for_review'],
            '__APPROVED__' => (string) $summary['approved_track'],
            '__EXPORTED__' => (string) $summary['exported'],
            '__WEAK__' => (string) $summary['weak_fields'],
            '__ATTENTION_ITEMS__' => $attentionItems,
            '__ROWS__' => $rows,
        ];

        return new Response(str_replace(array_keys($replacements), array_values($replacements), $html), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function exportCsv(int|string $cohortId): Response
    {
        $cohort = null;
        foreach ($this->cohortOverview->loadCohorts() as $loadedCohort) {
            if ((string) $loadedCohort->id() === (string) $cohortId) {
                $cohort = $loadedCohort;
                break;
            }
        }

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
        $cohort = null;
        foreach ($this->cohortOverview->loadCohorts() as $loadedCohort) {
            if ((string) $loadedCohort->id() === (string) $cohortId) {
                $cohort = $loadedCohort;
                break;
            }
        }

        if (!$cohort instanceof EntityInterface) {
            return new Response('Cohort not found.', 404);
        }

        $bundle = $this->cohortBundleService->build($cohort);
        $response = new BinaryFileResponse($bundle['path']);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $bundle['filename']);

        return $response;
    }

    private function renderCohortCard(EntityInterface $cohort): string
    {
        $summary = $this->cohortOverview->summarizeCohort($cohort);
        $url = '/cohorts/' . rawurlencode((string) $cohort->id());

        return sprintf(
            '<article class="card"><div class="eyebrow">Pilot Cohort</div><h2><a href="%s">%s</a></h2><p>Status: %s · Capacity: %s · Window: %s to %s</p><div class="metrics"><div class="metric"><strong>%d</strong><span>Submissions</span></div><div class="metric"><strong>%d</strong><span>Ready / approved</span></div><div class="metric"><strong>%d/%d</strong><span>Appendix signoff</span></div></div><p class="meta"><span>Weak fields open: %d</span><span><a href="%s">Open cohort board</a></span></p></article>',
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($cohort->label() ?? 'Cohort'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($cohort->get('status') ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($cohort->get('capacity') ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($cohort->get('starts_at') ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($cohort->get('ends_at') ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $summary['total'],
            $summary['ready_for_review'] + $summary['approved_track'],
            $summary['reviewed_appendices'],
            $summary['appendix_total'],
            $summary['weak_fields'],
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
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

    private function renderSubmissionRow(EntityInterface $submission): string
    {
        $review = $this->reviewService->summarizeSubmission($submission);
        $readiness = $this->cohortOverview->readinessSummary($submission);
        $artifactAudit = $this->artifactAuditService->summarize($submission);
        $submissionUrl = '/submissions/' . rawurlencode((string) $submission->id());
        $updated = (string) ($review['latest_created_at'] ?: ($submission->get('started_at') ?? 'n/a'));

        return sprintf(
            '<tr><td><strong>%s</strong><br>%s · %s</td><td>%s<br>%s</td><td>%s</td><td>%d/%d appendices<br>%d actions</td><td>%d weak · %d unresolved</td><td>%d/%d ready</td><td>%s</td><td><div class="links"><a href="%s">Workspace</a><a href="%s/review">Review</a><a href="%s/exports">Exports</a></div></td></tr>',
            htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($submission->get('applicant_name') ?? 'Unknown applicant'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($submission->get('business_name') ?? 'Unknown business'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($submission->get('status') ?? 'draft'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($submission->get('current_step') ?? 'n/a'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($readiness['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            (int) $review['reviewed_appendix_count'],
            (int) $review['reviewed_appendix_total'],
            (int) $review['review_count'],
            (int) $readiness['weak_count'],
            (int) $readiness['unresolved_count'],
            (int) $artifactAudit['ready_count'],
            (int) $artifactAudit['total_count'],
            htmlspecialchars((string) $updated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function renderAttentionItem(EntityInterface $submission): string
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
            return '';
        }

        $submissionUrl = '/submissions/' . rawurlencode((string) $submission->id());

        return sprintf(
            '<p><strong>%s</strong>: %s. <a href="%s">Open workspace</a> · <a href="%s/review">Review</a> · <a href="%s/exports">Exports</a></p>',
            htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(implode(' · ', $issues), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($submissionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
