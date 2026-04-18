<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cohort\CohortOverviewService;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class DashboardController
{
    public function __construct(
        private readonly ApplicationGraphGenerator $graphGenerator,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly CohortOverviewService $cohortOverview,
    ) {}

    public function index(): Response
    {
        $graph = $this->graphGenerator->generate()->toArray();
        $graphJson = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $submissionStorage = $this->entityTypeManager->getStorage('proposal_submission');
        $documentStorage = $this->entityTypeManager->getStorage('proposal_document');
        $submissionIds = $submissionStorage->getQuery()->execute();
        $submissions = array_values($submissionStorage->loadMultiple($submissionIds));
        $cohorts = $this->cohortOverview->loadCohorts();
        $bundleIds = $documentStorage->getQuery()
            ->condition('document_type', 'artifact_bundle_zip')
            ->execute();
        $bundles = $documentStorage->loadMultiple($bundleIds);
        $bundleCount = 0;
        foreach ($bundles as $bundle) {
            $path = (string) ($bundle->get('storage_path') ?? '');
            if ($path !== '' && is_file($path)) {
                $bundleCount++;
            }
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Waaseyaa Proposals</title>
  <style>
    :root {
      --ink: #1b1916;
      --sand: #f5f0e8;
      --paper: #fffdf9;
      --earth: #8d6a43;
      --moss: #2e5a46;
      --rust: #bf5b2c;
      --line: #ddd1c0;
      --muted: #6d6458;
      --code: #1f2937;
      --code-ink: #e5edf7;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      color: var(--ink);
      background:
        radial-gradient(circle at top right, rgba(191, 91, 44, 0.10), transparent 28%),
        radial-gradient(circle at left center, rgba(46, 90, 70, 0.10), transparent 24%),
        linear-gradient(180deg, #f7f2ea 0%, var(--sand) 100%);
    }
    main {
      max-width: 1120px;
      margin: 0 auto;
      padding: 48px 24px 64px;
    }
    .hero {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 24px;
      align-items: start;
      margin-bottom: 28px;
    }
    .card {
      background: rgba(255, 253, 249, 0.92);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 10px 35px rgba(81, 66, 49, 0.06);
    }
    h1 {
      margin: 0 0 10px;
      font-size: clamp(2.2rem, 5vw, 4rem);
      line-height: 0.96;
      letter-spacing: -0.04em;
      font-weight: 600;
    }
    .lede {
      margin: 0;
      max-width: 58ch;
      color: var(--muted);
      font-size: 1.08rem;
      line-height: 1.7;
    }
    .eyebrow, .mini {
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 0.72rem;
      color: var(--earth);
      font-weight: 700;
    }
    .stack {
      display: grid;
      gap: 16px;
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      margin-top: 22px;
    }
    .stat {
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
    }
    .stat strong {
      display: block;
      font-size: 1.8rem;
      color: var(--moss);
    }
    .stat span {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .panels {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 24px;
      margin-top: 24px;
    }
    ol {
      margin: 14px 0 0;
      padding-left: 20px;
      line-height: 1.8;
    }
    li + li { margin-top: 6px; }
    .links {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 18px;
    }
    .links a {
      display: inline-flex;
      align-items: center;
      padding: 10px 14px;
      border-radius: 999px;
      text-decoration: none;
      background: var(--moss);
      color: #fff;
      font-size: 0.92rem;
    }
    .links a.secondary {
      background: transparent;
      color: var(--moss);
      border: 1px solid var(--line);
    }
    pre {
      margin: 12px 0 0;
      padding: 18px;
      border-radius: 14px;
      overflow: auto;
      background: var(--code);
      color: var(--code-ink);
      font-size: 0.85rem;
      line-height: 1.6;
    }
    ul {
      margin: 14px 0 0;
      padding-left: 18px;
      line-height: 1.8;
    }
    @media (max-width: 900px) {
      .hero, .panels, .stats { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <div class="card">
        <div class="eyebrow">NorthOps on Waaseyaa</div>
        <h1>Waaseyaa Proposals</h1>
        <p class="lede">
          The first real product shell is live. This app starts from a schema-first proposal domain,
          not a generic form runner, and it already carries Bimaaji for graph introspection and
          sovereignty-aware mutation guardrails from the start.
        </p>
        <div class="stats">
          <div class="stat"><strong>__SUBMISSION_COUNT__</strong><span>Submission workspaces live</span></div>
          <div class="stat"><strong>__COHORT_COUNT__</strong><span>Cohorts tracked</span></div>
          <div class="stat"><strong>6</strong><span>ISET appendices targeted</span></div>
          <div class="stat"><strong>__EXPORTED_COUNT__</strong><span>Exports currently on disk</span></div>
        </div>
        <div class="links">
          <a href="/submissions">Open submissions surface</a>
          <a class="secondary" href="/cohorts">Open cohort board</a>
          <a class="secondary" href="/api">Inspect API surface</a>
        </div>
      </div>
      <div class="stack">
        <div class="card">
          <div class="mini">Current Build</div>
          <ul>
            <li>Proposal entities registered</li>
            <li>NorthOps sovereignty profile active</li>
            <li>Bimaaji graph available for app introspection</li>
            <li>Review, exports, and cohort surfaces in place</li>
          </ul>
        </div>
        <div class="card">
          <div class="mini">Next Code</div>
          <ul>
            <li>Cohort dashboard for multi-participant operations</li>
            <li>Deterministic readiness checks against generated artifacts</li>
            <li>Bounded AI provider behind intake orchestration</li>
            <li>More than one seeded participant submission</li>
          </ul>
        </div>
      </div>
    </section>

    <section class="panels">
      <div class="card">
        <div class="eyebrow">Architecture Spine</div>
        <ol>
          <li><strong>Pipeline definitions</strong> hold reusable form maps and workflow metadata.</li>
          <li><strong>Submissions</strong> hold canonical data, transcript state, validation, and unresolved items.</li>
          <li><strong>Documents</strong> capture deterministic generated artifacts from canonical state.</li>
          <li><strong>Reviews</strong> track staff comments and approval flow.</li>
          <li><strong>Bimaaji</strong> exposes app graph and sovereignty context as machine-readable structure.</li>
        </ol>
      </div>
      <div class="card">
        <div class="eyebrow">Bimaaji Graph Snapshot</div>
        <pre>__GRAPH_JSON__</pre>
      </div>
    </section>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['__GRAPH_JSON__', '__SUBMISSION_COUNT__', '__COHORT_COUNT__', '__EXPORTED_COUNT__'],
            [
                htmlspecialchars((string) $graphJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (string) count($submissions),
                (string) count($cohorts),
                (string) $bundleCount,
            ],
            $html,
        );

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
