<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cohort\CohortOverviewService;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class DashboardController
{
    public function __construct(
        private readonly ApplicationGraphGenerator $graphGenerator,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly CohortOverviewService $cohortOverview,
        private readonly Environment $twig,
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

        $html = $this->twig->render('pages/dashboard/index.html.twig', [
            'graph_json' => (string) $graphJson,
            'submission_count' => count($submissions),
            'cohort_count' => count($cohorts),
            'exported_count' => $bundleCount,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
