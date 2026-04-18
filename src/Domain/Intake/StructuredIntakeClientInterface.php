<?php

declare(strict_types=1);

namespace App\Domain\Intake;

interface StructuredIntakeClientInterface
{
    public function providerName(): string;

    public function isEnabled(): bool;

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
    public function processTurn(string $message, ?array $activeQuestion, array $canonicalData, array $unresolved, array $researchContext): ?array;
}
