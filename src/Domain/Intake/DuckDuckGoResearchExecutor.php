<?php

declare(strict_types=1);

namespace App\Domain\Intake;

final class DuckDuckGoResearchExecutor implements ResearchExecutorInterface
{
    private const SEARCH_URL = 'https://html.duckduckgo.com/html/';
    private const BING_SEARCH_URL = 'https://www.bing.com/search';
    private const WIKIPEDIA_SEARCH_URL = 'https://en.wikipedia.org/w/api.php';

    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $resultLimit = 3,
        private readonly string $searchUrl = self::SEARCH_URL,
    ) {}

    public function providerName(): string
    {
        return 'duckduckgo';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function executeRequests(array $requests): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach (array_slice($requests, 0, 2) as $request) {
            $query = trim((string) ($request['query'] ?? ''));
            $kind = trim((string) ($request['kind'] ?? ''));

            if ($query === '' || $kind === '') {
                continue;
            }

            [$provider, $citations] = $this->search($query);
            $results[] = [
                'kind' => $kind,
                'query' => $query,
                'provider' => $provider,
                'status' => $citations === [] ? 'empty' : 'ok',
                'summary' => $this->summarize($query, $citations),
                'citations' => $citations,
                'executed_at' => gmdate(DATE_ATOM),
            ];
        }

        return $results;
    }

    /**
     * @return array{0:string,1:list<array{title:string,url:string,snippet:string}>}
     */
    private function search(string $query): array
    {
        $duckDuckGo = $this->searchDuckDuckGo($query);
        if ($duckDuckGo !== []) {
            return [$this->providerName(), $duckDuckGo];
        }

        $bing = $this->searchBing($query);
        if ($bing !== []) {
            return ['bing', $bing];
        }

        return ['wikipedia', $this->searchWikipedia($query)];
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchDuckDuckGo(string $query): array
    {
        $ch = curl_init($this->searchUrl);
        if ($ch === false) {
            return [];
        }

        $body = http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: MiikanaResearchBot/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return [];
        }

        if (str_contains($response, 'anomaly-modal') || str_contains($response, 'Unfortunately, bots use DuckDuckGo too.')) {
            return [];
        }

        $matches = [];
        preg_match_all(
            '/<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?<a[^>]*class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/a>/si',
            $response,
            $matches,
            PREG_SET_ORDER,
        );

        $results = [];

        foreach (array_slice($matches, 0, $this->resultLimit) as $match) {
            $url = html_entity_decode(strip_tags((string) ($match[1] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = trim(html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $snippet = trim(html_entity_decode(strip_tags((string) ($match[3] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

            if ($title === '' || $url === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchBing(string $query): array
    {
        $url = self::BING_SEARCH_URL . '?' . http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return [];
        }

        $matches = [];
        preg_match_all(
            '/<li class="b_algo".*?<h2><a href="([^"]+)"[^>]*>(.*?)<\/a><\/h2>.*?(?:<p>(.*?)<\/p>)?/si',
            $response,
            $matches,
            PREG_SET_ORDER,
        );

        $results = [];

        foreach (array_slice($matches, 0, $this->resultLimit) as $match) {
            $url = html_entity_decode(strip_tags((string) ($match[1] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = trim(html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $snippet = trim(html_entity_decode(strip_tags((string) ($match[3] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

            if ($title === '' || $url === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchWikipedia(string $query): array
    {
        $url = self::WIKIPEDIA_SEARCH_URL . '?' . http_build_query([
            'action' => 'query',
            'list' => 'search',
            'format' => 'json',
            'utf8' => '1',
            'srlimit' => (string) $this->resultLimit,
            'srsearch' => $query,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: MiikanaResearchBot/1.0 (research loop)',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = $decoded['query']['search'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $snippet = trim(html_entity_decode(strip_tags((string) ($item['snippet'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($title === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)),
                'snippet' => $snippet,
            ];
        }

        return $results;
    }

    /**
     * @param list<array{title:string,url:string,snippet:string}> $citations
     */
    private function summarize(string $query, array $citations): string
    {
        if ($citations === []) {
            return sprintf('No external results were captured for "%s".', $query);
        }

        $parts = [];
        foreach ($citations as $citation) {
            $parts[] = $citation['title'] . ($citation['snippet'] !== '' ? ': ' . $citation['snippet'] : '');
        }

        return implode(' | ', $parts);
    }
}
