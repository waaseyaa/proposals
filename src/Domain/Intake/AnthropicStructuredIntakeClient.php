<?php

declare(strict_types=1);

namespace App\Domain\Intake;

final class AnthropicStructuredIntakeClient implements StructuredIntakeClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * @param list<string> $allowedPaths
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly array $allowedPaths,
        private readonly int $maxTokens = 700,
        private readonly string $apiUrl = self::API_URL,
    ) {}

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function isEnabled(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->model) !== '';
    }

    public function processTurn(string $message, ?array $activeQuestion, array $canonicalData, array $unresolved, array $researchContext): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $payload = [
            'submission_focus' => [
                'active_question' => $activeQuestion,
                'unresolved' => array_values($unresolved),
                'allowed_paths' => array_values($this->allowedPaths),
            ],
            'current_canonical_excerpt' => $this->canonicalExcerpt($canonicalData),
            'research_context' => $this->researchExcerpt($researchContext),
            'user_message' => $message,
        ];

        $response = $this->sendRequest($payload);
        if ($response === null) {
            return null;
        }

        $decoded = $this->decodeJsonResponse($response);
        if ($decoded === null) {
            return null;
        }

        $assistantMessage = trim((string) ($decoded['assistant_message'] ?? ''));
        $patches = is_array($decoded['patches'] ?? null) ? $decoded['patches'] : [];
        $unresolvedHints = is_array($decoded['unresolved_hints'] ?? null) ? $decoded['unresolved_hints'] : [];
        $researchRequests = is_array($decoded['research_requests'] ?? null) ? $decoded['research_requests'] : [];
        $normalizedPatches = [];
        $normalizedHints = [];
        $normalizedResearchRequests = [];

        foreach ($patches as $patch) {
            if (!is_array($patch)) {
                continue;
            }

            $path = strtolower(trim((string) ($patch['path'] ?? '')));
            if ($path === '' || !in_array($path, $this->allowedPaths, true)) {
                continue;
            }

            $normalizedPatches[] = [
                'path' => $path,
                'value' => $patch['value'] ?? '',
            ];
        }

        foreach ($unresolvedHints as $hint) {
            $path = strtolower(trim((string) $hint));
            if ($path === '' || !in_array($path, $this->allowedPaths, true)) {
                continue;
            }

            $normalizedHints[] = $path;
        }

        foreach ($researchRequests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $kind = strtolower(trim((string) ($request['kind'] ?? '')));
            $query = trim((string) ($request['query'] ?? ''));

            if ($kind === '' || $query === '') {
                continue;
            }

            $normalizedResearchRequests[] = [
                'kind' => $kind,
                'query' => $query,
            ];
        }

        if ($assistantMessage === '' && $normalizedPatches === [] && $normalizedHints === [] && $normalizedResearchRequests === []) {
            return null;
        }

        return [
            'assistant_message' => $assistantMessage,
            'patches' => $normalizedPatches,
            'unresolved_hints' => array_values(array_unique($normalizedHints)),
            'research_requests' => $normalizedResearchRequests,
        ];
    }

    /**
     * @param array<string,mixed> $canonicalData
     * @return array<string,mixed>
     */
    private function canonicalExcerpt(array $canonicalData): array
    {
        return [
            'applicant' => [
                'contact' => $canonicalData['applicant']['contact'] ?? [],
            ],
            'business' => [
                'identity' => $canonicalData['business']['identity'] ?? [],
                'operations' => $canonicalData['business']['operations'] ?? [],
                'market' => $canonicalData['business']['market'] ?? [],
            ],
            'funding_request' => $canonicalData['funding_request'] ?? [],
            'career_plan' => [
                'three_year_plan' => $canonicalData['career_plan']['three_year_plan'] ?? null,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $researchContext
     * @return list<array<string,mixed>>
     */
    private function researchExcerpt(array $researchContext): array
    {
        $excerpt = [];

        foreach (array_slice(array_reverse($researchContext), 0, 3) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $excerpt[] = [
                'kind' => (string) ($item['kind'] ?? ''),
                'query' => (string) ($item['query'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'citations' => array_slice(is_array($item['citations'] ?? null) ? $item['citations'] : [], 0, 2),
            ];
        }

        return array_values(array_filter($excerpt, static fn (array $item): bool => $item['query'] !== ''));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sendRequest(array $payload): ?string
    {
        $body = json_encode([
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $this->systemPrompt(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        $contentBlocks = $decoded['content'] ?? null;
        if (!is_array($contentBlocks)) {
            return null;
        }

        $text = '';
        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return trim($text);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonResponse(string $response): ?array
    {
        $trimmed = trim($response);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract structured proposal intake data for a bounded application workflow.

Return JSON only. Do not wrap it in markdown fences.

Output shape:
{
  "assistant_message": "one short reviewer-safe sentence",
  "patches": [
    {"path": "dot.path", "value": "..."}
  ],
  "unresolved_hints": ["dot.path"],
  "research_requests": [
    {"kind": "market_validation", "query": "short research query"}
  ]
}

Rules:
- Only use paths from submission_focus.allowed_paths.
- Extract only facts clearly supported by the current user_message.
- If the message does not clearly support a patch, return an empty patches array.
- unresolved_hints should contain only fields that still need follow-up based on the current turn.
- research_requests are advisory only. Include them only when a concrete external fact lookup would materially help the next step.
- research_context contains prior captured findings. Use it only as background for interpretation, not as a reason to invent new patches.
- Keep research queries short and sourceable, usually 3-10 words, not a full sentence.
- Keep assistant_message short, concrete, and professional.
- For business.market.customers, prefer an array of customer groups.
- For applicant.contact.email, return a lowercase email string.
- For applicant.contact.telephone, return a phone string without commentary.
- Never invent facts from prior context. Current_canonical_excerpt is only for disambiguation.
PROMPT;
    }
}
