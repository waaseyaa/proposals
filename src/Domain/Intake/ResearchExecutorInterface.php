<?php

declare(strict_types=1);

namespace App\Domain\Intake;

interface ResearchExecutorInterface
{
    public function providerName(): string;

    public function isEnabled(): bool;

    /**
     * @param list<array{kind:string,query:string}> $requests
     * @return list<array{
     *   kind:string,
     *   query:string,
     *   provider:string,
     *   status:string,
     *   summary:string,
     *   citations:list<array{title:string,url:string,snippet:string}>,
     *   executed_at:string
     * }>
     */
    public function executeRequests(array $requests): array;
}
