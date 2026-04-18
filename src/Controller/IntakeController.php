<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Intake\DeterministicIntakeService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IntakeController
{
    public function __construct(
        private readonly DeterministicIntakeService $intakeService,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->intakeService->loadSubmission($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $transcript = is_array($submission->get('intake_transcript')) ? $submission->get('intake_transcript') : [];
        $transcriptHtml = '';
        foreach ($transcript as $turn) {
            $transcriptHtml .= $this->renderTurn($turn);
        }
        if ($transcriptHtml === '') {
            $transcriptHtml = '<div class="empty">No intake turns recorded yet.</div>';
        }

        $unresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $unresolvedHtml = $unresolved === []
            ? '<div class="empty">No unresolved core intake fields.</div>'
            : '<ul><li>' . implode('</li><li>', array_map(
                static fn (string $item): string => htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $unresolved,
            )) . '</li></ul>';
        $researchLog = is_array($submission->get('research_log')) ? $submission->get('research_log') : [];
        $researchHtml = $this->renderResearchLog($researchLog);
        $nextQuestion = $this->intakeService->nextQuestionForSubmission($submission);
        $nextQuestionHtml = $this->renderNextQuestion($nextQuestion);
        $providerBadge = htmlspecialchars($this->intakeService->providerMode(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $notice = $this->noticeFromUri($_SERVER['REQUEST_URI'] ?? '');
        $title = htmlspecialchars((string) ($submission->label() ?? 'Submission'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $submissionIdEscaped = htmlspecialchars((string) $submission->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Intake · Miikana</title>
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
      --assistant: #eef5f0;
      --user: #fff7ee;
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
    p, li { line-height: 1.8; color: var(--muted); }
    .layout {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 24px;
    }
    .stack {
      display: grid;
      gap: 16px;
    }
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
    .turn {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
    }
    .turn.user { background: var(--user); }
    .turn.assistant { background: var(--assistant); }
    .turn-meta {
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--muted);
      margin-bottom: 8px;
      font-weight: 700;
    }
    .patches {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid var(--line);
      font-size: 0.92rem;
      color: var(--muted);
    }
    .patch-list {
      display: grid;
      gap: 10px;
    }
    .patch-item {
      border: 1px solid rgba(49, 88, 69, 0.14);
      border-radius: 12px;
      padding: 12px;
      background: rgba(255,255,255,0.55);
    }
    .patch-item code {
      font-size: 0.84rem;
    }
    .patch-meta {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 6px;
      font-size: 0.84rem;
    }
    .patch-note {
      margin-top: 6px;
      color: var(--ink);
    }
    .advisories {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid var(--line);
      font-size: 0.92rem;
      color: var(--muted);
    }
    .advisory-list {
      display: grid;
      gap: 8px;
      margin-top: 8px;
    }
    .advisory-item {
      border: 1px solid rgba(186, 90, 45, 0.16);
      border-radius: 12px;
      padding: 10px 12px;
      background: rgba(255,255,255,0.52);
    }
    .confidence {
      font-weight: 700;
      color: var(--moss);
    }
    .follow-up {
      color: var(--rust);
      font-weight: 700;
    }
    form {
      display: grid;
      gap: 12px;
    }
    textarea, button {
      font: inherit;
    }
    textarea {
      width: 100%;
      min-height: 180px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--ink);
      resize: vertical;
    }
    button {
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: var(--moss);
      color: #fff;
      cursor: pointer;
      justify-self: start;
    }
    code {
      background: #f3ede5;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 2px 6px;
    }
    .notice, .empty {
      border-radius: 12px;
      padding: 12px 14px;
    }
    .question-card {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: #fffefd;
    }
    .question-card strong {
      display: block;
      margin-bottom: 6px;
    }
    .priority {
      display: inline-block;
      margin-top: 10px;
      padding: 4px 10px;
      border-radius: 999px;
      background: var(--card);
      color: var(--rust);
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-weight: 700;
    }
    .notice {
      border: 1px solid rgba(33, 102, 58, 0.18);
      background: rgba(33, 102, 58, 0.08);
      color: #21663a;
      font-weight: 700;
      margin-top: 16px;
    }
    .empty {
      border: 1px dashed var(--line);
      background: #fffaf5;
      color: var(--muted);
    }
    ul {
      margin: 0;
      padding-left: 18px;
    }
    @media (max-width: 960px) {
      .layout { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="eyebrow">Conversational Intake</div>
      <h1>__TITLE__</h1>
      <p>This is the bounded intake loop: every turn is stored, structured patches are applied into <code>canonical_data</code>, and unresolved fields stay visible instead of disappearing into chat history.</p>
      <p><strong>Provider mode:</strong> <code>__PROVIDER__</code></p>
      <div class="nav">
        <a href="/submissions">Back to submissions</a>
        <a href="/submissions/__ID__">Structured data</a>
        <a href="/submissions/__ID__/documents">Appendix documents</a>
        <a href="/submissions/__ID__/review">Review</a>
      </div>
      __NOTICE__
    </section>
    <section class="layout">
      <div class="stack">
        <section class="card">
          <div class="eyebrow">Transcript</div>
          __TRANSCRIPT__
        </section>
      </div>
      <div class="stack">
        <section class="card">
          <div class="eyebrow">New Turn</div>
          __NEXT_QUESTION__
          <p>Use direct field lines like <code>business.operations.launch_timeline: Within 10 days</code> or short statements like <code>customers include Band office, local families</code>.</p>
          <form method="post" action="/submissions/__ID__/intake">
            <textarea name="message" placeholder="Describe the business, answer a follow-up question, or send direct field lines."></textarea>
            <button type="submit">Apply Intake Turn</button>
          </form>
        </section>
        <section class="card">
          <div class="eyebrow">Unresolved Core Fields</div>
          __UNRESOLVED__
        </section>
      </div>
    </section>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__ID__', '__NOTICE__', '__TRANSCRIPT__', '__UNRESOLVED__', '__NEXT_QUESTION__', '__PROVIDER__'],
            [$title, $submissionIdEscaped, $notice, $transcriptHtml . '<section class="card"><div class="eyebrow">Research Log</div>' . $researchHtml . '</section>', $unresolvedHtml, $nextQuestionHtml, $providerBadge],
            $html,
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function handle(Request $request, int|string $submissionId): Response
    {
        try {
            $this->intakeService->handleTurn(
                $submissionId,
                (string) $request->request->get('message', ''),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/intake?error=intake');
        }

        return new RedirectResponse('/submissions/' . rawurlencode((string) $submissionId) . '/intake?updated=intake');
    }

    /**
     * @param array<string,mixed> $turn
     */
    private function renderTurn(array $turn): string
    {
        $role = (string) ($turn['role'] ?? 'system');
        $content = nl2br(htmlspecialchars((string) ($turn['content'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $createdAt = htmlspecialchars((string) ($turn['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $provider = trim((string) ($turn['provider'] ?? ''));
        $patches = is_array($turn['patches'] ?? null) ? $turn['patches'] : [];
        $providerUnresolvedHints = is_array($turn['provider_unresolved_hints'] ?? null) ? $turn['provider_unresolved_hints'] : [];
        $providerResearchRequests = is_array($turn['provider_research_requests'] ?? null) ? $turn['provider_research_requests'] : [];
        $executedResearch = is_array($turn['executed_research'] ?? null) ? $turn['executed_research'] : [];
        $researchInsights = is_array($turn['research_insights'] ?? null) ? $turn['research_insights'] : [];
        $patchHtml = '';
        $advisoryHtml = '';

        if ($patches !== []) {
            $items = [];
            foreach ($patches as $patch) {
                if (!is_array($patch)) {
                    continue;
                }
                $path = htmlspecialchars((string) ($patch['path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $confidence = isset($patch['confidence']) ? number_format((float) $patch['confidence'], 2) : 'n/a';
                $note = htmlspecialchars((string) ($patch['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $resolved = (bool) ($patch['resolved'] ?? true);
                $items[] = sprintf(
                    '<div class="patch-item"><code>%s</code><div class="patch-meta"><span class="confidence">confidence %s</span><span class="%s">%s</span></div><div class="patch-note">%s</div></div>',
                    $path,
                    $confidence,
                    $resolved ? 'confidence' : 'follow-up',
                    $resolved ? 'resolved' : 'follow-up needed',
                    $note,
                );
            }
            if ($items !== []) {
                $patchHtml = '<div class="patches"><strong>Applied:</strong><div class="patch-list">' . implode('', $items) . '</div></div>';
            }
        }

        $advisories = [];

        if ($providerUnresolvedHints !== []) {
            $items = [];
            foreach ($providerUnresolvedHints as $hint) {
                $items[] = sprintf(
                    '<div class="advisory-item"><strong>Follow-up hint</strong><div><code>%s</code></div></div>',
                    htmlspecialchars((string) $hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
            $advisories[] = '<div><strong>Provider Hints</strong><div class="advisory-list">' . implode('', $items) . '</div></div>';
        }

        if ($providerResearchRequests !== []) {
            $items = [];
            foreach ($providerResearchRequests as $request) {
                if (!is_array($request)) {
                    continue;
                }
                $kind = htmlspecialchars((string) ($request['kind'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $query = htmlspecialchars((string) ($request['query'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $items[] = sprintf(
                    '<div class="advisory-item"><strong>%s</strong><div>%s</div></div>',
                    $kind,
                    $query,
                );
            }
            if ($items !== []) {
                $advisories[] = '<div><strong>Research Requests</strong><div class="advisory-list">' . implode('', $items) . '</div></div>';
            }
        }

        if ($executedResearch !== []) {
            $items = [];
            foreach ($executedResearch as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $kind = htmlspecialchars((string) ($item['kind'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $summary = htmlspecialchars((string) ($item['summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $items[] = sprintf(
                    '<div class="advisory-item"><strong>%s executed</strong><div>%s</div></div>',
                    $kind,
                    $summary,
                );
            }
            if ($items !== []) {
                $advisories[] = '<div><strong>Executed Research</strong><div class="advisory-list">' . implode('', $items) . '</div></div>';
            }
        }

        if ($researchInsights !== []) {
            $items = [];
            foreach ($researchInsights as $insight) {
                $items[] = sprintf(
                    '<div class="advisory-item"><strong>Research Insight</strong><div>%s</div></div>',
                    htmlspecialchars((string) $insight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
            $advisories[] = '<div><strong>Research Summary</strong><div class="advisory-list">' . implode('', $items) . '</div></div>';
        }

        if ($advisories !== []) {
            $advisoryHtml = '<div class="advisories">' . implode('', $advisories) . '</div>';
        }

        $providerMeta = $provider !== '' ? ' · ' . htmlspecialchars($provider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

        return sprintf(
            '<article class="turn %s"><div class="turn-meta">%s · %s</div><div>%s</div>%s%s</article>',
            htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $providerMeta,
            $createdAt !== '' ? $createdAt : 'n/a',
            $content !== '' ? $content : '&nbsp;',
            $patchHtml,
            $advisoryHtml,
        );
    }

    private function noticeFromUri(string $uri): string
    {
        return match (true) {
            str_contains($uri, 'updated=intake') => '<div class="notice">Intake turn stored and structured patches applied.</div>',
            str_contains($uri, 'error=intake') => '<div class="notice">Unable to apply the intake turn. Check the message and try again.</div>',
            default => '',
        };
    }

    /**
     * @param array{path:string,priority:string,question:string,example:string}|null $nextQuestion
     */
    private function renderNextQuestion(?array $nextQuestion): string
    {
        if ($nextQuestion === null) {
            return '<div class="empty">No queued next question. The current question plan is satisfied.</div>';
        }

        return sprintf(
            '<div class="question-card"><strong>Next Question</strong><div>%s</div><p><code>%s</code></p><span class="priority">%s priority</span></div>',
            htmlspecialchars($nextQuestion['question'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($nextQuestion['example'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($nextQuestion['priority'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param list<array<string,mixed>> $researchLog
     */
    private function renderResearchLog(array $researchLog): string
    {
        if ($researchLog === []) {
            return '<div class="empty">No research artifacts captured yet.</div>';
        }

        $items = [];

        foreach (array_slice(array_reverse($researchLog), 0, 5) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!isset($item['query']) && !isset($item['summary'])) {
                continue;
            }

            $kind = htmlspecialchars((string) ($item['kind'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $query = htmlspecialchars((string) ($item['query'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = htmlspecialchars((string) ($item['summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $citations = is_array($item['citations'] ?? null) ? $item['citations'] : [];

            $citationHtml = '';
            if ($citations !== []) {
                $links = [];
                foreach (array_slice($citations, 0, 3) as $citation) {
                    if (!is_array($citation)) {
                        continue;
                    }
                    $title = htmlspecialchars((string) ($citation['title'] ?? 'Source'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $url = htmlspecialchars((string) ($citation['url'] ?? '#'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if (str_starts_with((string) ($citation['url'] ?? ''), '/')) {
                        $links[] = sprintf('<div><strong>%s</strong><div><code>%s</code></div></div>', $title, $url);
                    } else {
                        $links[] = sprintf('<div><a href="%s" target="_blank" rel="noreferrer">%s</a></div>', $url, $title);
                    }
                }
                if ($links !== []) {
                    $citationHtml = '<div class="advisories"><strong>Citations</strong>' . implode('', $links) . '</div>';
                }
            }

            $items[] = sprintf(
                '<div class="patch-item"><code>%s</code><div class="patch-note">%s</div><div class="patch-meta"><span class="confidence">%s</span></div>%s</div>',
                $kind . ($query !== '' ? ': ' . $query : ''),
                $summary !== '' ? $summary : 'No summary available.',
                htmlspecialchars((string) ($item['provider'] ?? 'research'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $citationHtml,
            );
        }

        return '<div class="patch-list">' . implode('', $items) . '</div>';
    }
}
