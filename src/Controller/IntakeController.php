<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Intake\DeterministicIntakeService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class IntakeController
{
    public function __construct(
        private readonly DeterministicIntakeService $intakeService,
        private readonly Environment $twig,
    ) {}

    public function show(int|string $submissionId): Response
    {
        $submission = $this->intakeService->loadSubmission($submissionId);
        if ($submission === null) {
            return new Response('Submission not found.', 404);
        }

        $transcript = is_array($submission->get('intake_transcript')) ? $submission->get('intake_transcript') : [];
        $unresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $researchLog = is_array($submission->get('research_log')) ? $submission->get('research_log') : [];

        $context = [
            'submission_title' => (string) ($submission->label() ?? 'Submission'),
            'submission_id' => (string) $submission->id(),
            'provider_mode' => $this->intakeService->providerMode(),
            'notice' => $this->noticeKey($_SERVER['REQUEST_URI'] ?? ''),
            'transcript' => $this->buildTranscriptView($transcript),
            'unresolved_items' => array_values(array_map(static fn ($item): string => (string) $item, $unresolved)),
            'research_log' => [
                'raw_empty' => $researchLog === [],
                'items' => $this->buildResearchLogView($researchLog),
            ],
            'next_question' => $this->intakeService->nextQuestionForSubmission($submission),
        ];

        $html = $this->twig->render('pages/intake/show.html.twig', $context);

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
     * @param list<array<string,mixed>> $transcript
     * @return list<array<string,mixed>>
     */
    private function buildTranscriptView(array $transcript): array
    {
        $view = [];
        foreach ($transcript as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $view[] = $this->buildTurnView($turn);
        }
        return $view;
    }

    /**
     * @param array<string,mixed> $turn
     * @return array<string,mixed>
     */
    private function buildTurnView(array $turn): array
    {
        $role = (string) ($turn['role'] ?? 'system');
        $content = (string) ($turn['content'] ?? '');
        $createdAt = (string) ($turn['created_at'] ?? '');
        $provider = trim((string) ($turn['provider'] ?? ''));

        $patches = [];
        foreach (is_array($turn['patches'] ?? null) ? $turn['patches'] : [] as $patch) {
            if (!is_array($patch)) {
                continue;
            }
            $patches[] = [
                'path' => (string) ($patch['path'] ?? ''),
                'confidence' => isset($patch['confidence']) ? number_format((float) $patch['confidence'], 2) : 'n/a',
                'note' => (string) ($patch['note'] ?? ''),
                'resolved' => (bool) ($patch['resolved'] ?? true),
            ];
        }

        $advisories = [];

        $hints = is_array($turn['provider_unresolved_hints'] ?? null) ? $turn['provider_unresolved_hints'] : [];
        if ($hints !== []) {
            $items = [];
            foreach ($hints as $hint) {
                $items[] = [
                    'title' => 'Follow-up hint',
                    'body' => (string) $hint,
                    'body_is_code' => true,
                ];
            }
            $advisories[] = ['heading' => 'Provider Hints', 'items' => $items];
        }

        $requests = is_array($turn['provider_research_requests'] ?? null) ? $turn['provider_research_requests'] : [];
        if ($requests !== []) {
            $items = [];
            foreach ($requests as $request) {
                if (!is_array($request)) {
                    continue;
                }
                $items[] = [
                    'title' => (string) ($request['kind'] ?? ''),
                    'body' => (string) ($request['query'] ?? ''),
                    'body_is_code' => false,
                ];
            }
            if ($items !== []) {
                $advisories[] = ['heading' => 'Research Requests', 'items' => $items];
            }
        }

        $executed = is_array($turn['executed_research'] ?? null) ? $turn['executed_research'] : [];
        if ($executed !== []) {
            $items = [];
            foreach ($executed as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'title' => ((string) ($item['kind'] ?? '')) . ' executed',
                    'body' => (string) ($item['summary'] ?? ''),
                    'body_is_code' => false,
                ];
            }
            if ($items !== []) {
                $advisories[] = ['heading' => 'Executed Research', 'items' => $items];
            }
        }

        $insights = is_array($turn['research_insights'] ?? null) ? $turn['research_insights'] : [];
        if ($insights !== []) {
            $items = [];
            foreach ($insights as $insight) {
                $items[] = [
                    'title' => 'Research Insight',
                    'body' => (string) $insight,
                    'body_is_code' => false,
                ];
            }
            $advisories[] = ['heading' => 'Research Summary', 'items' => $items];
        }

        return [
            'role' => $role,
            'provider_meta' => $provider,
            'created_at' => $createdAt,
            'content' => $content,
            'patches' => $patches,
            'advisories' => $advisories,
        ];
    }

    /**
     * @param list<array<string,mixed>> $researchLog
     * @return list<array<string,mixed>>
     */
    private function buildResearchLogView(array $researchLog): array
    {
        $view = [];

        foreach (array_slice(array_reverse($researchLog), 0, 5) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['query']) && !isset($item['summary'])) {
                continue;
            }

            $kind = (string) ($item['kind'] ?? 'research');
            $query = (string) ($item['query'] ?? '');
            $summary = (string) ($item['summary'] ?? '');

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

            $view[] = [
                'header' => $kind . ($query !== '' ? ': ' . $query : ''),
                'summary' => $summary !== '' ? $summary : 'No summary available.',
                'provider' => (string) ($item['provider'] ?? 'research'),
                'citations' => $citations,
            ];
        }

        return $view;
    }

    private function noticeKey(string $uri): ?string
    {
        return match (true) {
            str_contains($uri, 'updated=intake') => 'updated',
            str_contains($uri, 'error=intake') => 'error',
            default => null,
        };
    }
}
