<?php

declare(strict_types=1);

namespace App\Domain\Intake;

final class LocalCorpusResearchExecutor implements ResearchExecutorInterface
{
    /**
     * @param list<string> $roots
     * @param list<string> $extensions
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $roots,
        private readonly int $resultLimit = 3,
        private readonly array $extensions = ['md', 'txt', 'html', 'json'],
    ) {}

    public function providerName(): string
    {
        return 'local_corpus';
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

            $citations = $this->searchCorpus($query);
            $results[] = [
                'kind' => $kind,
                'query' => $query,
                'provider' => $this->providerName(),
                'status' => $citations === [] ? 'empty' : 'ok',
                'summary' => $this->summarize($query, $citations),
                'citations' => $citations,
                'executed_at' => gmdate(DATE_ATOM),
            ];
        }

        return $results;
    }

    /**
     * @return list<array{title:string,url:string,snippet:string}>
     */
    private function searchCorpus(string $query): array
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $matches = [];

        foreach ($this->candidateFiles() as $path) {
            $contents = @file_get_contents($path);
            if (!is_string($contents) || trim($contents) === '') {
                continue;
            }

            $normalized = $this->normalizeContents($contents, $path);
            if ($normalized === '') {
                continue;
            }

            $score = $this->scoreContents($normalized, $tokens, $path);
            if ($score <= 0) {
                continue;
            }

            $matches[] = [
                'score' => $score,
                'citation' => [
                    'title' => basename($path),
                    'url' => $path,
                    'snippet' => $this->buildSnippet($normalized, $tokens),
                ],
            ];
        }

        usort(
            $matches,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
        );

        return array_values(array_map(
            static fn (array $match): array => $match['citation'],
            array_slice($matches, 0, $this->resultLimit),
        ));
    }

    /**
     * @return list<string>
     */
    private function candidateFiles(): array
    {
        $files = [];

        foreach ($this->roots as $root) {
            if (!is_dir($root) && !is_file($root)) {
                continue;
            }

            if (is_file($root)) {
                if ($this->supports($root)) {
                    $files[] = $root;
                }
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $path = $item->getPathname();
                if ($this->supports($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->extensions, true)) {
            return false;
        }

        $basename = basename($path);
        if (str_starts_with($basename, '.')) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [];
        $parts = array_values(array_filter(
            $parts,
            static fn (string $part): bool => strlen($part) >= 4
        ));

        return array_values(array_unique($parts));
    }

    /**
     * @param list<string> $tokens
     */
    private function scoreContents(string $contents, array $tokens, string $path): int
    {
        $normalized = strtolower($contents);
        $pathText = strtolower(str_replace(['/', '-', '_', '.'], ' ', $path));
        $score = 0;

        foreach ($tokens as $token) {
            if (str_contains($normalized, $token)) {
                $score += 2;
            }

            if (str_contains($pathText, $token)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * @param list<string> $tokens
     */
    private function buildSnippet(string $contents, array $tokens): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $contents) ?? '');
        if ($normalized === '') {
            return 'No snippet available.';
        }

        $lower = strtolower($normalized);
        $position = null;

        foreach ($tokens as $token) {
            $found = strpos($lower, $token);
            if ($found === false) {
                continue;
            }

            $position = $position === null ? $found : min($position, $found);
        }

        if ($position === null) {
            return mb_substr($normalized, 0, 240);
        }

        $start = max(0, $position - 80);
        $snippet = mb_substr($normalized, $start, 260);

        return trim($snippet);
    }

    private function normalizeContents(string $contents, string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'html') {
            $contents = $this->extractHtmlText($contents);
        }

        if ($extension === 'json') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $contents = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $contents;
            }
        }

        $contents = html_entity_decode(strip_tags($contents), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $contents = preg_replace('/--[a-z0-9-]+\s*:\s*[^;]+;?/i', ' ', $contents) ?? $contents;
        $contents = preg_replace('/[{}<>]{2,}/', ' ', $contents) ?? $contents;
        $contents = preg_replace('/\s+/', ' ', $contents) ?? $contents;

        return trim($contents);
    }

    private function extractHtmlText(string $contents): string
    {
        if (!class_exists(\DOMDocument::class)) {
            $contents = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $contents) ?? $contents;
            $contents = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $contents) ?? $contents;

            return $contents;
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($contents, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $contents;
        }

        foreach (['script', 'style', 'noscript', 'svg'] as $tagName) {
            while (true) {
                $nodes = $dom->getElementsByTagName($tagName);
                if ($nodes->length === 0) {
                    break;
                }

                $node = $nodes->item(0);
                if ($node === null || $node->parentNode === null) {
                    break;
                }

                $node->parentNode->removeChild($node);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body?->textContent ?? $dom->textContent ?? $contents;

        return is_string($text) ? $text : $contents;
    }

    /**
     * @param list<array{title:string,url:string,snippet:string}> $citations
     */
    private function summarize(string $query, array $citations): string
    {
        if ($citations === []) {
            return sprintf('No local corpus results were captured for "%s".', $query);
        }

        $parts = [];
        foreach ($citations as $citation) {
            $parts[] = $citation['title'] . ': ' . $citation['snippet'];
        }

        return implode(' | ', $parts);
    }
}
