<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Review\ProposalReviewService;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class DeterministicIntakeService
{
    private const QUESTION_PLAN = [
        [
            'path' => 'business.identity.business_name',
            'priority' => 'core',
            'question' => 'What is the business name you want on the proposal package?',
            'example' => 'business.identity.business_name: NorthOps',
        ],
        [
            'path' => 'business.operations.launch_timeline',
            'priority' => 'core',
            'question' => 'What is the launch timeline once support is approved?',
            'example' => 'business.operations.launch_timeline: Within 14 days of SEA approval',
        ],
        [
            'path' => 'business.market.customers',
            'priority' => 'core',
            'question' => 'Who are the first customer groups you expect to serve?',
            'example' => 'business.market.customers: ["First Nation organizations", "small businesses"]',
        ],
        [
            'path' => 'funding_request.support_rationale',
            'priority' => 'core',
            'question' => 'Why is self-employment support necessary at this stage?',
            'example' => 'funding_request.support_rationale: I need support to cover startup runway while securing early contracts.',
        ],
        [
            'path' => 'business.market.marketing_plan',
            'priority' => 'core',
            'question' => 'How will you attract your first clients?',
            'example' => 'business.market.marketing_plan: Direct outreach, referrals, and community presentations',
        ],
        [
            'path' => 'business.operations.location',
            'priority' => 'expanded',
            'question' => 'Where will the business operate from?',
            'example' => 'business.operations.location: Sagamok Anishnawbek, Ontario',
        ],
        [
            'path' => 'career_plan.three_year_plan',
            'priority' => 'expanded',
            'question' => 'What does success look like over the next three years?',
            'example' => 'career_plan.three_year_plan: Build a sustainable consulting practice with recurring clients and local hires.',
        ],
        [
            'path' => 'applicant.contact.email',
            'priority' => 'expanded',
            'question' => 'What email should reviewers use for submission follow-up?',
            'example' => 'applicant.contact.email: you@example.org',
        ],
        [
            'path' => 'applicant.contact.telephone',
            'priority' => 'expanded',
            'question' => 'What phone number should go on the package?',
            'example' => 'applicant.contact.telephone: 705-555-0101',
        ],
    ];

    public function __construct(
        private readonly EntityStorageInterface $submissionStorage,
        private readonly ProposalReviewService $reviewService,
        private readonly ?StructuredIntakeClientInterface $structuredIntakeClient = null,
        private readonly ?ResearchExecutorInterface $researchExecutor = null,
    ) {}

    public function providerMode(): string
    {
        if ($this->structuredIntakeClient !== null && $this->structuredIntakeClient->isEnabled()) {
            return $this->structuredIntakeClient->providerName();
        }

        return 'deterministic';
    }

    public function loadSubmission(int|string $submissionId): ?EntityInterface
    {
        return $this->submissionStorage->load($submissionId);
    }

    /**
     * @return array{
     *   assistant_message:string,
     *   patches:list<array{path:string,value:mixed,confidence:float,note:string,resolved:bool}>,
     *   unresolved:list<string>,
     *   transcript:list<array<string,mixed>>,
     *   next_question:?array{path:string,priority:string,question:string,example:string},
     *   provider:string,
     *   provider_unresolved_hints:list<string>,
     *   provider_research_requests:list<array{kind:string,query:string}>,
     *   executed_research:list<array<string,mixed>>,
     *   research_insights:list<string>
     * }
     */
    public function handleTurn(int|string $submissionId, string $message): array
    {
        $submission = $this->loadSubmission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submissionId));
        }

        $trimmedMessage = trim($message);
        if ($trimmedMessage === '') {
            throw new \InvalidArgumentException('Intake message cannot be empty.');
        }

        $canonicalData = is_array($submission->get('canonical_data')) ? $submission->get('canonical_data') : [];
        $transcript = is_array($submission->get('intake_transcript')) ? $submission->get('intake_transcript') : [];
        $researchLog = is_array($submission->get('research_log')) ? $submission->get('research_log') : [];
        $previousConfidenceState = is_array($submission->get('confidence_state')) ? $submission->get('confidence_state') : [];
        $previousStatus = (string) ($submission->get('status') ?? 'draft');
        $existingUnresolved = is_array($submission->get('unresolved_items')) ? $submission->get('unresolved_items') : [];
        $activeQuestion = $this->nextQuestionForData(
            $canonicalData,
            $existingUnresolved,
        );
        $providerResponse = $this->extractProviderTurn($trimmedMessage, $activeQuestion, $canonicalData, $existingUnresolved, $researchLog);
        $provider = $providerResponse !== null ? $this->providerMode() : 'deterministic';
        $providerUnresolvedHints = $providerResponse['unresolved_hints'] ?? [];
        $providerResearchRequests = $providerResponse['research_requests'] ?? [];
        if ($providerResearchRequests === []) {
            $providerResearchRequests = $this->inferResearchRequests($trimmedMessage, $activeQuestion, $canonicalData);
        }
        $executedResearch = $this->executeResearchRequests($providerResearchRequests);
        $researchInsights = $this->buildResearchInsights($executedResearch);
        if ($executedResearch !== []) {
            $researchLog = array_merge($researchLog, $executedResearch);
        }
        $rawPatches = $providerResponse['patches'] ?? $this->extractPatches($trimmedMessage, $activeQuestion);
        $patches = $this->annotatePatches($rawPatches, $activeQuestion);
        $unresolved = $this->deriveUnresolvedItems($canonicalData, $patches);

        foreach ($patches as $patch) {
            $this->setValueAtPath($canonicalData, $patch['path'], $patch['value']);
        }

        $transcript[] = [
            'role' => 'user',
            'content' => $trimmedMessage,
            'created_at' => gmdate(DATE_ATOM),
        ];

        $nextQuestion = $this->nextQuestionForData($canonicalData, $unresolved);
        $assistantMessage = $this->buildAssistantMessage(
            $patches,
            $unresolved,
            $nextQuestion,
            $providerResponse['assistant_message'] ?? null,
            $executedResearch,
        );
        $transcript[] = [
            'role' => 'assistant',
            'content' => $assistantMessage,
            'created_at' => gmdate(DATE_ATOM),
            'patches' => $patches,
            'unresolved' => $unresolved,
            'next_question' => $nextQuestion,
            'provider' => $provider,
            'provider_unresolved_hints' => $providerUnresolvedHints,
            'provider_research_requests' => $providerResearchRequests,
            'executed_research' => $executedResearch,
            'research_insights' => $researchInsights,
        ];

        $submission->set('canonical_data', $canonicalData);
        $submission->set('intake_transcript', $transcript);
        $submission->set('conversation_summary', $this->summarizeTranscript($transcript));
        $submission->set('unresolved_items', $unresolved);
        $submission->set('research_log', $researchLog);
        $nextConfidenceState = $this->mergeConfidenceState($previousConfidenceState, $patches);
        $submission->set('confidence_state', $nextConfidenceState);
        $submission->set('status', $patches === [] ? (string) ($submission->get('status') ?? 'draft') : 'intake_in_progress');
        $submission->set('current_step', 'intake');
        $this->submissionStorage->save($submission);

        if ($patches !== [] && $previousStatus !== 'intake_in_progress') {
            $this->reviewService->recordSystemAction(
                $submission->id(),
                'Submission returned to intake',
                sprintf('A new intake turn applied structured patches and moved the submission from %s to intake_in_progress.', $previousStatus),
            );
        }

        foreach ($this->resolvedConfidencePaths($previousConfidenceState, $nextConfidenceState) as $path) {
            $state = $nextConfidenceState[$path] ?? [];
            $this->reviewService->recordSystemAction(
                $submission->id(),
                'Low-confidence field resolved',
                sprintf(
                    '%s is now resolved at confidence %.2f. %s',
                    $path,
                    (float) ($state['confidence'] ?? 0),
                    (string) ($state['note'] ?? ''),
                ),
                'intake',
                $path,
                'system',
            );
        }

        return [
            'assistant_message' => $assistantMessage,
            'patches' => $patches,
            'unresolved' => $unresolved,
            'transcript' => $transcript,
            'next_question' => $nextQuestion,
            'provider' => $provider,
            'provider_unresolved_hints' => $providerUnresolvedHints,
            'provider_research_requests' => $providerResearchRequests,
            'executed_research' => $executedResearch,
            'research_insights' => $researchInsights,
        ];
    }

    /**
     * @return array{path:string,priority:string,question:string,example:string}|null
     */
    public function nextQuestionForSubmission(int|string|EntityInterface $submission): ?array
    {
        $submissionEntity = $submission instanceof EntityInterface
            ? $submission
            : $this->loadSubmission($submission);

        if ($submissionEntity === null) {
            throw new \RuntimeException(sprintf('Submission "%s" not found.', (string) $submission));
        }

        $canonicalData = is_array($submissionEntity->get('canonical_data')) ? $submissionEntity->get('canonical_data') : [];
        $unresolved = is_array($submissionEntity->get('unresolved_items')) ? $submissionEntity->get('unresolved_items') : [];

        return $this->nextQuestionForData($canonicalData, $unresolved);
    }

    /**
     * @param array{path:string,priority:string,question:string,example:string}|null $activeQuestion
     * @return list<array{path:string,value:mixed}>
     */
    private function extractPatches(string $message, ?array $activeQuestion = null): array
    {
        $patches = [];
        $lines = preg_split('/\r\n|\r|\n/', $message) ?: [$message];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^([a-z0-9_.]+)\s*:\s*(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = [
                    'path' => strtolower(trim($matches[1])),
                    'value' => $this->coerceValue(trim($matches[2])),
                ];
                continue;
            }

            if (preg_match('/business name is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'business.identity.business_name', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/launch timeline is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'business.operations.launch_timeline', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/customers include\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'business.market.customers', 'value' => $this->splitList(trim($matches[1], " ."))];
                continue;
            }

            if (preg_match('/support rationale is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'funding_request.support_rationale', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/business location is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'business.operations.location', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/email is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'applicant.contact.email', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/phone is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'applicant.contact.telephone', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/marketing plan is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'business.market.marketing_plan', 'value' => trim($matches[1], " .")];
                continue;
            }

            if (preg_match('/three year plan is\s+(.+)$/i', $trimmed, $matches) === 1) {
                $patches[] = ['path' => 'career_plan.three_year_plan', 'value' => trim($matches[1], " .")];
                continue;
            }
        }

        if ($patches === [] && $activeQuestion !== null && !$this->isResearchSeekingMessage($message)) {
            $inferred = $this->inferPatchFromActiveQuestion($message, $activeQuestion['path']);
            if ($inferred !== null) {
                $patches[] = $inferred;
            }
        }

        return $patches;
    }

    /**
     * @param list<array{path:string,value:mixed}> $patches
     * @param array{path:string,priority:string,question:string,example:string}|null $activeQuestion
     * @return list<array{path:string,value:mixed,confidence:float,note:string,resolved:bool}>
     */
    private function annotatePatches(array $patches, ?array $activeQuestion): array
    {
        $annotated = [];

        foreach ($patches as $patch) {
            $quality = $this->evaluatePatchQuality($patch['path'], $patch['value'], $activeQuestion);
            $annotated[] = [
                'path' => $patch['path'],
                'value' => $patch['value'],
                'confidence' => $quality['confidence'],
                'note' => $quality['note'],
                'resolved' => $quality['resolved'],
            ];
        }

        return $annotated;
    }

    /**
     * @param array<string,mixed> $canonicalData
     * @param list<array{path:string,value:mixed,confidence:float,note:string,resolved:bool}> $patches
     * @return list<string>
     */
    private function deriveUnresolvedItems(array $canonicalData, array $patches): array
    {
        foreach ($patches as $patch) {
            $this->setValueAtPath($canonicalData, $patch['path'], $patch['value']);
        }

        $requiredPaths = [
            'business.identity.business_name',
            'business.operations.launch_timeline',
            'business.market.customers',
            'funding_request.support_rationale',
            'business.market.marketing_plan',
        ];

        $unresolved = [];

        foreach ($requiredPaths as $path) {
            $value = $this->valueAtPath($canonicalData, $path);
            if ($value === null || $value === '' || $value === []) {
                $unresolved[] = $path;
            }
        }

        foreach ($patches as $patch) {
            if ($patch['resolved'] === false && !in_array($patch['path'], $unresolved, true)) {
                $unresolved[] = $patch['path'];
            }
        }

        return $unresolved;
    }

    /**
     * @param list<array{path:string,value:mixed,confidence:float,note:string,resolved:bool}> $patches
     * @param list<string> $unresolved
     * @param array{path:string,priority:string,question:string,example:string}|null $nextQuestion
     */
    private function buildAssistantMessage(array $patches, array $unresolved, ?array $nextQuestion, ?string $providerLead = null, array $executedResearch = []): string
    {
        $lead = trim((string) $providerLead);

        if ($patches === []) {
            if ($executedResearch !== []) {
                $summary = $this->summarizeExecutedResearch($executedResearch);
                $message = $lead !== ''
                    ? rtrim($lead, " \t\n\r\0\x0B.") . '. '
                    : '';
                $message .= 'Research captured for this turn: ' . $summary . '.';
                if ($nextQuestion !== null) {
                    $message .= ' Next question: ' . $nextQuestion['question'] . ' Example: ' . $nextQuestion['example'] . '.';
                }

                return $message;
            }

            if ($nextQuestion !== null) {
                return sprintf(
                    'I did not extract a structured patch from that turn. Next question: %s Use a direct field line like %s.',
                    $nextQuestion['question'],
                    $nextQuestion['example'],
                );
            }

            return 'I did not extract a structured patch from that turn. Use direct field lines such as business.operations.launch_timeline: Within 14 days of SEA approval.';
        }

        $paths = array_map(static fn (array $patch): string => $patch['path'], $patches);
        $message = $lead !== ''
            ? rtrim($lead, " \t\n\r\0\x0B.") . '. Structured patch applied for: ' . implode(', ', $paths) . '.'
            : 'Structured patch applied for: ' . implode(', ', $paths) . '.';

        $needsFollowUp = array_values(array_filter(
            $patches,
            static fn (array $patch): bool => $patch['resolved'] === false
        ));

        if ($needsFollowUp !== []) {
            $followUps = array_map(
                static fn (array $patch): string => sprintf('%s (%s)', $patch['path'], $patch['note']),
                $needsFollowUp,
            );
            $message .= ' Follow-up needed: ' . implode(', ', $followUps) . '.';
        } elseif ($unresolved !== []) {
            $message .= ' Still missing: ' . implode(', ', $unresolved) . '.';
        } else {
            $message .= ' Core intake fields are now populated for this first-pass workflow.';
        }

        if ($nextQuestion !== null) {
            $message .= ' Next question: ' . $nextQuestion['question'] . ' Example: ' . $nextQuestion['example'] . '.';
        }

        return $message;
    }

    /**
     * @param mixed $value
     * @param array{path:string,priority:string,question:string,example:string}|null $activeQuestion
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluatePatchQuality(string $path, mixed $value, ?array $activeQuestion): array
    {
        $matchedActiveQuestion = $activeQuestion !== null && $activeQuestion['path'] === $path;

        return match ($path) {
            'funding_request.support_rationale' => $this->evaluateNarrativePatch($value, $matchedActiveQuestion, 14, 'Add more detail about timing, risk, or what the support unlocks.'),
            'business.market.marketing_plan' => $this->evaluateNarrativePatch($value, $matchedActiveQuestion, 10, 'Add specific channels, outreach tactics, or early conversion strategy.'),
            'career_plan.three_year_plan' => $this->evaluateNarrativePatch($value, $matchedActiveQuestion, 12, 'Add clearer three-year milestones, scale targets, or hiring intent.'),
            'business.market.customers' => $this->evaluateCustomerPatch($value, $matchedActiveQuestion),
            'applicant.contact.email' => $this->evaluateEmailPatch($value, $matchedActiveQuestion),
            'applicant.contact.telephone' => $this->evaluateTelephonePatch($value, $matchedActiveQuestion),
            'business.identity.business_name',
            'business.operations.launch_timeline',
            'business.operations.location' => $this->evaluateSimpleTextPatch($value, $matchedActiveQuestion),
            default => [
                'confidence' => $matchedActiveQuestion ? 0.82 : 0.95,
                'note' => $matchedActiveQuestion ? 'Mapped from the active intake question.' : 'Mapped from an explicit field or phrase.',
                'resolved' => true,
            ],
        };
    }

    /**
     * @param mixed $value
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluateNarrativePatch(mixed $value, bool $matchedActiveQuestion, int $minimumWords, string $followUp): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        $wordCount = $text === '' ? 0 : count(array_values(array_filter(preg_split('/\s+/', $text) ?: [])));

        if ($wordCount < $minimumWords) {
            return [
                'confidence' => $matchedActiveQuestion ? 0.74 : 0.84,
                'note' => $followUp,
                'resolved' => false,
            ];
        }

        return [
            'confidence' => $matchedActiveQuestion ? 0.86 : 0.95,
            'note' => $matchedActiveQuestion ? 'Narrative mapped from the active intake question.' : 'Narrative mapped from an explicit field or phrase.',
            'resolved' => true,
        ];
    }

    /**
     * @param mixed $value
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluateCustomerPatch(mixed $value, bool $matchedActiveQuestion): array
    {
        $count = is_array($value) ? count(array_values(array_filter($value, static fn (mixed $item): bool => trim((string) $item) !== ''))) : 0;

        if ($count < 2) {
            return [
                'confidence' => $matchedActiveQuestion ? 0.72 : 0.82,
                'note' => 'List at least two concrete customer groups.',
                'resolved' => false,
            ];
        }

        return [
            'confidence' => $matchedActiveQuestion ? 0.85 : 0.95,
            'note' => $matchedActiveQuestion ? 'Customer groups inferred from the active intake question.' : 'Customer groups mapped from explicit input.',
            'resolved' => true,
        ];
    }

    /**
     * @param mixed $value
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluateEmailPatch(mixed $value, bool $matchedActiveQuestion): array
    {
        $text = trim((string) $value);
        $valid = filter_var($text, FILTER_VALIDATE_EMAIL) !== false;

        return [
            'confidence' => $valid ? ($matchedActiveQuestion ? 0.9 : 0.98) : 0.3,
            'note' => $valid
                ? ($matchedActiveQuestion ? 'Email inferred from the active intake question.' : 'Email mapped from explicit input.')
                : 'Email format looks invalid and needs correction.',
            'resolved' => $valid,
        ];
    }

    /**
     * @param mixed $value
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluateTelephonePatch(mixed $value, bool $matchedActiveQuestion): array
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        $valid = strlen($digits) >= 10;

        return [
            'confidence' => $valid ? ($matchedActiveQuestion ? 0.88 : 0.97) : 0.35,
            'note' => $valid
                ? ($matchedActiveQuestion ? 'Phone number inferred from the active intake question.' : 'Phone number mapped from explicit input.')
                : 'Phone number looks incomplete and needs follow-up.',
            'resolved' => $valid,
        ];
    }

    /**
     * @param mixed $value
     * @return array{confidence:float,note:string,resolved:bool}
     */
    private function evaluateSimpleTextPatch(mixed $value, bool $matchedActiveQuestion): array
    {
        $text = trim((string) $value);
        $resolved = $text !== '' && strlen($text) >= 3;

        return [
            'confidence' => $resolved ? ($matchedActiveQuestion ? 0.84 : 0.95) : 0.4,
            'note' => $resolved
                ? ($matchedActiveQuestion ? 'Mapped from the active intake question.' : 'Mapped from explicit input.')
                : 'The answer is too short and needs clarification.',
            'resolved' => $resolved,
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param list<array{path:string,value:mixed,confidence:float,note:string,resolved:bool}> $patches
     * @return array<string,mixed>
     */
    private function mergeConfidenceState(array $existing, array $patches): array
    {
        foreach ($patches as $patch) {
            $existing[$patch['path']] = [
                'confidence' => $patch['confidence'],
                'note' => $patch['note'],
                'resolved' => $patch['resolved'],
                'updated_at' => gmdate(DATE_ATOM),
            ];
        }

        return $existing;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return list<string>
     */
    private function resolvedConfidencePaths(array $previous, array $current): array
    {
        $resolved = [];

        foreach ($current as $path => $state) {
            if (!is_array($state) || ($state['resolved'] ?? false) !== true) {
                continue;
            }

            $previousState = $previous[$path] ?? null;
            if (is_array($previousState) && ($previousState['resolved'] ?? true) === false) {
                $resolved[] = (string) $path;
            }
        }

        return $resolved;
    }

    /**
     * @return array{path:string,value:mixed}|null
     */
    private function inferPatchFromActiveQuestion(string $message, string $path): ?array
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;

        return match ($path) {
            'business.identity.business_name' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'business.operations.launch_timeline' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'funding_request.support_rationale' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'business.market.marketing_plan' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'business.operations.location' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'career_plan.three_year_plan' => [
                'path' => $path,
                'value' => trim($normalized, " ."),
            ],
            'applicant.contact.email' => $this->inferEmailPatch($path, $normalized),
            'applicant.contact.telephone' => $this->inferTelephonePatch($path, $normalized),
            'business.market.customers' => [
                'path' => $path,
                'value' => $this->splitList($normalized),
            ],
            default => null,
        };
    }

    /**
     * @return array{path:string,value:mixed}|null
     */
    private function inferEmailPatch(string $path, string $value): ?array
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches) !== 1) {
            return null;
        }

        return [
            'path' => $path,
            'value' => strtolower($matches[0]),
        ];
    }

    /**
     * @return array{path:string,value:mixed}|null
     */
    private function inferTelephonePatch(string $path, string $value): ?array
    {
        if (preg_match('/\+?[\d\s().-]{7,}/', $value, $matches) !== 1) {
            return null;
        }

        return [
            'path' => $path,
            'value' => rtrim(trim($matches[0]), '.,;:'),
        ];
    }

    /**
     * @param array<string,mixed> $canonicalData
     * @param list<string> $unresolved
     * @return array{path:string,priority:string,question:string,example:string}|null
     */
    private function nextQuestionForData(array $canonicalData, array $unresolved): ?array
    {
        if ($unresolved !== []) {
            foreach (self::QUESTION_PLAN as $question) {
                if (in_array($question['path'], $unresolved, true)) {
                    return $question;
                }
            }
        }

        foreach (self::QUESTION_PLAN as $question) {
            $value = $this->valueAtPath($canonicalData, $question['path']);
            if ($value === null || $value === '' || $value === []) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $transcript
     */
    private function summarizeTranscript(array $transcript): string
    {
        $assistantTurns = array_values(array_filter(
            $transcript,
            static fn (array $turn): bool => ($turn['role'] ?? '') === 'assistant'
        ));

        if ($assistantTurns === []) {
            return 'No intake summary available yet.';
        }

        $latest = $assistantTurns[array_key_last($assistantTurns)];

        return (string) ($latest['content'] ?? 'No intake summary available yet.');
    }

    private function coerceValue(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if (
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
            || (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
        ) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $trimmed;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $parts = preg_split('/\s*,\s*|\s+and\s+/i', $value) ?: [];
        $parts = array_map(static fn (string $part): string => trim($part), $parts);
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function valueAtPath(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setValueAtPath(array &$data, string $path, mixed $value): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return;
        }

        $current =& $data;

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

    /**
     * @param array{path:string,priority:string,question:string,example:string}|null $activeQuestion
     * @param array<string,mixed> $canonicalData
     * @param list<string> $unresolved
     * @param list<array<string,mixed>> $researchContext
     * @return array{
     *   assistant_message:string,
     *   patches:list<array{path:string,value:mixed}>,
     *   unresolved_hints:list<string>,
      *   research_requests:list<array{kind:string,query:string}>
     * }|null
     */
    private function extractProviderTurn(string $message, ?array $activeQuestion, array $canonicalData, array $unresolved, array $researchContext): ?array
    {
        if ($this->structuredIntakeClient === null || !$this->structuredIntakeClient->isEnabled()) {
            return null;
        }

        $response = $this->structuredIntakeClient->processTurn($message, $activeQuestion, $canonicalData, $unresolved, $researchContext);
        if ($response === null) {
            return null;
        }

        $normalized = [];
        foreach ($response['patches'] as $patch) {
            $normalizedPatch = $this->normalizePatch($patch);
            if ($normalizedPatch !== null) {
                $normalized[] = $normalizedPatch;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return [
            'assistant_message' => trim((string) ($response['assistant_message'] ?? '')),
            'patches' => $normalized,
            'unresolved_hints' => $this->normalizeProviderUnresolvedHints($response['unresolved_hints'] ?? []),
            'research_requests' => $this->normalizeProviderResearchRequests($response['research_requests'] ?? []),
        ];
    }

    /**
     * @param list<array{kind:string,query:string}> $requests
     * @return list<array<string,mixed>>
     */
    private function executeResearchRequests(array $requests): array
    {
        if ($this->researchExecutor === null || !$this->researchExecutor->isEnabled() || $requests === []) {
            return [];
        }

        return $this->researchExecutor->executeRequests($requests);
    }

    /**
     * @param list<array<string,mixed>> $executedResearch
     * @return list<string>
     */
    private function buildResearchInsights(array $executedResearch): array
    {
        $insights = [];

        foreach ($executedResearch as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind = trim((string) ($item['kind'] ?? 'research'));
            $summary = trim((string) ($item['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }

            $insights[] = sprintf('%s: %s', $kind, $summary);
        }

        return $insights;
    }

    /**
     * @param list<array<string,mixed>> $executedResearch
     */
    private function summarizeExecutedResearch(array $executedResearch): string
    {
        $parts = [];

        foreach ($executedResearch as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind = trim((string) ($item['kind'] ?? 'research'));
            $provider = trim((string) ($item['provider'] ?? 'research'));
            $citations = is_array($item['citations'] ?? null) ? $item['citations'] : [];

            if ($citations !== []) {
                $title = trim((string) ($citations[0]['title'] ?? 'local source'));
                $parts[] = sprintf('%s via %s (%s)', $kind, $provider, $title);
                continue;
            }

            $parts[] = sprintf('%s via %s', $kind, $provider);
        }

        return $parts === [] ? 'no usable research findings were captured' : implode(', ', $parts);
    }

    /**
     * @param array{path:string,priority:string,question:string,example:string}|null $activeQuestion
     * @param array<string,mixed> $canonicalData
     * @return list<array{kind:string,query:string}>
     */
    private function inferResearchRequests(string $message, ?array $activeQuestion, array $canonicalData): array
    {
        if (!$this->isResearchSeekingMessage($message)) {
            return [];
        }

        $normalized = strtolower($message);
        $requests = [];

        if (str_contains($normalized, 'price') || str_contains($normalized, 'pricing') || str_contains($normalized, 'cost')) {
            $businessName = trim((string) ($canonicalData['business']['identity']['business_name'] ?? 'business'));
            $requests[] = [
                'kind' => 'costing',
                'query' => trim($businessName . ' startup costs'),
            ];
        }

        if (str_contains($normalized, 'competitor') || str_contains($normalized, 'market') || str_contains($normalized, 'demand') || str_contains($normalized, 'validate')) {
            $customers = is_array($canonicalData['business']['market']['customers'] ?? null)
                ? implode(' ', array_map(static fn (mixed $item): string => (string) $item, $canonicalData['business']['market']['customers']))
                : '';
            $fallback = $activeQuestion['path'] ?? 'business idea';
            $querySeed = trim($customers !== '' ? $customers : str_replace('.', ' ', $fallback));
            $requests[] = [
                'kind' => 'market_validation',
                'query' => trim($querySeed . ' proposal cohort market'),
            ];
        }

        if ($requests === []) {
            $requests[] = [
                'kind' => 'market_validation',
                'query' => 'proposal cohort business idea training',
            ];
        }

        $deduped = [];
        foreach ($requests as $request) {
            $query = trim($request['query']);
            $kind = trim($request['kind']);
            if ($query === '' || $kind === '') {
                continue;
            }
            $deduped[$kind . '|' . strtolower($query)] = ['kind' => $kind, 'query' => $query];
        }

        return array_values($deduped);
    }

    private function isResearchSeekingMessage(string $message): bool
    {
        $normalized = strtolower($message);

        foreach ([
            'research',
            'validate',
            'validation',
            'compare',
            'pricing',
            'price',
            'cost',
            'costs',
            'competitor',
            'market demand',
            'figure out',
            'look up',
            'help validating',
            'startup equipment',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{path:string,value:mixed} $patch
     * @return array{path:string,value:mixed}|null
     */
    private function normalizePatch(array $patch): ?array
    {
        $path = strtolower(trim((string) ($patch['path'] ?? '')));
        if ($path === '') {
            return null;
        }

        $value = $patch['value'] ?? '';

        if ($path === 'business.market.customers' && is_string($value)) {
            $value = $this->splitList($value);
        }

        if ($path === 'applicant.contact.email' && is_string($value)) {
            $value = strtolower(trim($value));
        }

        if ($path === 'applicant.contact.telephone' && is_string($value)) {
            $value = rtrim(trim($value), '.,;:');
        }

        if (
            !is_scalar($value)
            && !is_array($value)
            && $value !== null
        ) {
            return null;
        }

        return [
            'path' => $path,
            'value' => $value,
        ];
    }

    /**
     * @param list<mixed> $hints
     * @return list<string>
     */
    private function normalizeProviderUnresolvedHints(array $hints): array
    {
        $normalized = [];

        foreach ($hints as $hint) {
            $path = strtolower(trim((string) $hint));
            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<mixed> $requests
     * @return list<array{kind:string,query:string}>
     */
    private function normalizeProviderResearchRequests(array $requests): array
    {
        $normalized = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $kind = strtolower(trim((string) ($request['kind'] ?? '')));
            $query = trim((string) ($request['query'] ?? ''));

            if ($kind === '' || $query === '') {
                continue;
            }

            $normalized[] = [
                'kind' => $kind,
                'query' => $query,
            ];
        }

        return $normalized;
    }
}
