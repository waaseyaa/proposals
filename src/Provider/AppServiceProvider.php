<?php

declare(strict_types=1);

namespace App\Provider;

use App\Command\SeedNorthOpsCommand;
use App\Controller\CohortController;
use App\Controller\DashboardController;
use App\Controller\DocumentController;
use App\Controller\IntakeController;
use App\Controller\ReviewController;
use App\Controller\SubmissionController;
use App\Domain\Cohort\CohortOverviewService;
use App\Domain\Cohort\CohortBundleService;
use App\Domain\Generation\ArtifactAuditService;
use App\Domain\Generation\ArtifactBundleService;
use App\Domain\Generation\DocumentPreviewService;
use App\Domain\Generation\PdfGenerationService;
use App\Domain\Import\NorthOpsSeedImporter;
use App\Domain\Intake\AnthropicStructuredIntakeClient;
use App\Domain\Intake\DeterministicIntakeService;
use App\Domain\Intake\DuckDuckGoResearchExecutor;
use App\Domain\Intake\LocalCorpusResearchExecutor;
use App\Domain\Intake\ResearchExecutorInterface;
use App\Domain\Intake\StructuredIntakeClientInterface;
use App\Domain\Introspection\ProposalIntrospectionProvider;
use App\Domain\Review\ProposalReviewService;
use App\Support\ProposalSchemaBootstrap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Introspection\Sovereignty\SovereigntyIntrospectionProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\I18n\Translator;
use Waaseyaa\I18n\TranslatorInterface;
use Waaseyaa\I18n\Twig\TranslationTwigExtension;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(LanguageManagerInterface::class, function (): LanguageManagerInterface {
            $configured = (array) ($this->config['i18n']['languages'] ?? []);
            $languages = [];
            foreach ($configured as $entry) {
                if (!is_array($entry) || !isset($entry['id'], $entry['label'])) {
                    continue;
                }
                $languages[] = new Language(
                    (string) $entry['id'],
                    (string) $entry['label'],
                    isDefault: (bool) ($entry['is_default'] ?? false),
                );
            }
            if ($languages === []) {
                $languages[] = new Language('en', 'English', isDefault: true);
            }
            return new LanguageManager($languages);
        });

        $this->singleton(TranslatorInterface::class, function (): TranslatorInterface {
            $langPath = dirname(__DIR__, 2) . '/resources/lang';
            /** @var LanguageManagerInterface $manager */
            $manager = $this->resolve(LanguageManagerInterface::class);
            return new Translator($langPath, $manager);
        });

        $this->singleton(ProposalIntrospectionProvider::class, static fn() => new ProposalIntrospectionProvider());
        $this->singleton(EntityTypeManagerInterface::class, fn () => $this->resolve(EntityTypeManager::class));
        $this->singleton(ProposalSchemaBootstrap::class, fn () => new ProposalSchemaBootstrap(
            $this->resolve(EntityTypeManagerInterface::class),
            $this->resolve(DatabaseInterface::class),
        ));
        $this->singleton(NorthOpsSeedImporter::class, fn () => new NorthOpsSeedImporter(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_pipeline'),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_cohort'),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_submission'),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_review'),
        ));
        $this->singleton(CohortOverviewService::class, fn () => new CohortOverviewService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_cohort'),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_submission'),
            $this->resolve(ProposalReviewService::class),
        ));
        $this->singleton(CohortBundleService::class, fn () => new CohortBundleService(
            $this->resolve(CohortOverviewService::class),
            $this->resolve(ArtifactBundleService::class),
            $this->resolve(ArtifactAuditService::class),
            $this->resolve(ProposalReviewService::class),
            $this->projectRoot,
        ));
        $this->singleton(DocumentPreviewService::class, fn () => new DocumentPreviewService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_document'),
            $this->projectRoot,
        ));
        $this->singleton(StructuredIntakeClientInterface::class, function () {
            $provider = strtolower(trim((string) (getenv('INTAKE_AI_PROVIDER') ?: 'anthropic')));

            return new AnthropicStructuredIntakeClient(
                $provider === 'anthropic' ? (getenv('ANTHROPIC_API_KEY') ?: '') : '',
                getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6',
                [
                    'business.identity.business_name',
                    'business.operations.launch_timeline',
                    'business.market.customers',
                    'funding_request.support_rationale',
                    'business.market.marketing_plan',
                    'business.operations.location',
                    'career_plan.three_year_plan',
                    'applicant.contact.email',
                    'applicant.contact.telephone',
                ],
            );
        });
        $this->singleton(ResearchExecutorInterface::class, function () {
            $provider = strtolower(trim((string) (getenv('INTAKE_RESEARCH_PROVIDER') ?: 'local_corpus')));

            return match ($provider) {
                'duckduckgo' => new DuckDuckGoResearchExecutor(true),
                'local_corpus' => new LocalCorpusResearchExecutor(
                    true,
                    [
                        '/home/fsd42/NorthOps/knowledge-base',
                        '/home/fsd42/NorthOps/sources/OIATC',
                        '/home/fsd42/NorthOps/sources/mefunding-docs',
                        '/home/fsd42/NorthOps/PROJECT_STATUS.md',
                        '/home/fsd42/NorthOps/README.md',
                        '/home/fsd42/NorthOps/apps/iset-application/README.md',
                        '/home/fsd42/NorthOps/apps/oiatc-demo/README.md',
                    ],
                ),
                default => new LocalCorpusResearchExecutor(false, []),
            };
        });
        $this->singleton(DeterministicIntakeService::class, fn () => new DeterministicIntakeService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_submission'),
            $this->resolve(ProposalReviewService::class),
            $this->resolve(StructuredIntakeClientInterface::class),
            $this->resolve(ResearchExecutorInterface::class),
        ));
        $this->singleton(PdfGenerationService::class, fn () => new PdfGenerationService(
            $this->resolve(DocumentPreviewService::class),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_document'),
            $this->resolve(ProposalReviewService::class),
            $this->projectRoot,
        ));
        $this->singleton(ArtifactBundleService::class, fn () => new ArtifactBundleService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_document'),
            $this->resolve(DocumentPreviewService::class),
            $this->resolve(PdfGenerationService::class),
            $this->projectRoot,
        ));
        $this->singleton(ArtifactAuditService::class, fn () => new ArtifactAuditService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_document'),
        ));
        $this->singleton(ProposalReviewService::class, fn () => new ProposalReviewService(
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_submission'),
            $this->resolve(EntityTypeManagerInterface::class)->getStorage('proposal_review'),
        ));

        $this->singleton(ApplicationGraphGenerator::class, function (): ApplicationGraphGenerator {
            $profile = SovereigntyProfile::tryFrom((string) ($this->config['sovereignty']['profile'] ?? ''))
                ?? SovereigntyProfile::NorthOps;

            return new ApplicationGraphGenerator([
                new SovereigntyIntrospectionProvider($profile),
                $this->resolve(ProposalIntrospectionProvider::class),
            ]);
        });
    }

    public function boot(): void
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->resolve(TranslatorInterface::class);
        /** @var LanguageManagerInterface $manager */
        $manager = $this->resolve(LanguageManagerInterface::class);

        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig !== null) {
            $twig->addExtension(new TranslationTwigExtension($translator, $manager));
        }
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            throw new \RuntimeException('Twig environment not available; SsrServiceProvider::boot() must run before routes are registered.');
        }
        $dashboard = new DashboardController(
            $this->resolve(ApplicationGraphGenerator::class),
            $this->resolve(EntityTypeManagerInterface::class),
            $this->resolve(CohortOverviewService::class),
            $twig,
        );
        $submissions = new SubmissionController(
            $this->resolve(EntityTypeManagerInterface::class),
            $this->resolve(ArtifactAuditService::class),
            $this->resolve(ProposalReviewService::class),
            $twig,
        );
        $documents = new DocumentController(
            $this->resolve(EntityTypeManagerInterface::class),
            $this->resolve(DocumentPreviewService::class),
            $this->resolve(PdfGenerationService::class),
            $this->resolve(ArtifactBundleService::class),
            $this->resolve(ArtifactAuditService::class),
            $this->resolve(ProposalReviewService::class),
            $twig,
        );
        $intake = new IntakeController(
            $this->resolve(DeterministicIntakeService::class),
            $twig,
        );
        $cohorts = new CohortController(
            $this->resolve(CohortOverviewService::class),
            $this->resolve(CohortBundleService::class),
            $this->resolve(ArtifactAuditService::class),
            $this->resolve(ProposalReviewService::class),
            $twig,
        );
        $reviews = new ReviewController(
            $this->resolve(ProposalReviewService::class),
            $twig,
        );

        $router->addRoute(
            'home',
            RouteBuilder::create('/')
                ->controller(fn () => $dashboard->index())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'cohorts.index',
            RouteBuilder::create('/cohorts')
                ->controller(fn () => $cohorts->index())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'cohorts.show',
            RouteBuilder::create('/cohorts/{cohort}')
                ->controller(fn (Request $request, string $cohort) => $cohorts->show($cohort))
                ->allowAll()
                ->requirement('cohort', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'cohorts.export.csv',
            RouteBuilder::create('/cohorts/{cohort}/export.csv')
                ->controller(fn (Request $request, string $cohort) => $cohorts->exportCsv($cohort))
                ->allowAll()
                ->requirement('cohort', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'cohorts.bundle.download',
            RouteBuilder::create('/cohorts/{cohort}/bundle/download')
                ->controller(fn (Request $request, string $cohort) => $cohorts->downloadBundle($cohort))
                ->allowAll()
                ->requirement('cohort', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.index',
            RouteBuilder::create('/submissions')
                ->controller(fn () => $submissions->index())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.show',
            RouteBuilder::create('/submissions/{submission}')
                ->controller(fn (Request $request, string $submission) => $submissions->show($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.intake',
            RouteBuilder::create('/submissions/{submission}/intake')
                ->controller(fn (Request $request, string $submission) => $intake->show($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.intake.handle',
            RouteBuilder::create('/submissions/{submission}/intake')
                ->controller(fn (Request $request, string $submission) => $intake->handle($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.canonical.update',
            RouteBuilder::create('/submissions/{submission}/canonical')
                ->controller(fn (Request $request, string $submission) => $submissions->updateCanonical($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.research.draft.create',
            RouteBuilder::create('/submissions/{submission}/research/draft')
                ->controller(fn (Request $request, string $submission) => $submissions->createResearchDraft($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.research.draft.apply',
            RouteBuilder::create('/submissions/{submission}/research/drafts/{draft}/apply')
                ->controller(fn (Request $request, string $submission, string $draft) => $submissions->applyResearchDraft($submission, $draft))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.research.draft.reject',
            RouteBuilder::create('/submissions/{submission}/research/drafts/{draft}/reject')
                ->controller(fn (Request $request, string $submission, string $draft) => $submissions->rejectResearchDraft($submission, $draft))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.research.draft.restore',
            RouteBuilder::create('/submissions/{submission}/research/drafts/{draft}/restore')
                ->controller(fn (Request $request, string $submission, string $draft) => $submissions->restoreResearchDraft($submission, $draft))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.ready_for_review',
            RouteBuilder::create('/submissions/{submission}/ready-for-review')
                ->controller(fn (Request $request, string $submission) => $submissions->markReadyForReview($submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.documents',
            RouteBuilder::create('/submissions/{submission}/documents')
                ->controller(fn ($request, string $submission) => $documents->show($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.package',
            RouteBuilder::create('/submissions/{submission}/package')
                ->controller(fn ($request, string $submission) => $documents->package($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.package.pdf',
            RouteBuilder::create('/submissions/{submission}/package/pdf')
                ->controller(fn ($request, string $submission) => $documents->pdf($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.package.pdf.download',
            RouteBuilder::create('/submissions/{submission}/package/pdf/download')
                ->controller(fn ($request, string $submission) => $documents->downloadPdf($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.exports',
            RouteBuilder::create('/submissions/{submission}/exports')
                ->controller(fn ($request, string $submission) => $documents->exports($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.exports.file',
            RouteBuilder::create('/submissions/{submission}/exports/file/{document_type}')
                ->controller(fn ($request, string $submission, string $document_type) => $documents->exportFile($submission, $document_type))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->requirement('document_type', '[A-Za-z0-9_\-]+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.exports.file.download',
            RouteBuilder::create('/submissions/{submission}/exports/file/{document_type}/download')
                ->controller(fn ($request, string $submission, string $document_type) => $documents->downloadExportFile($submission, $document_type))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->requirement('document_type', '[A-Za-z0-9_\-]+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.exports.pdf.regenerate',
            RouteBuilder::create('/submissions/{submission}/exports/pdf/regenerate')
                ->controller(fn ($request, string $submission) => $documents->regeneratePdf($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.exports.bundle.download',
            RouteBuilder::create('/submissions/{submission}/exports/bundle/download')
                ->controller(fn ($request, string $submission) => $documents->downloadBundle($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.review',
            RouteBuilder::create('/submissions/{submission}/review')
                ->controller(fn (Request $request, string $submission) => $reviews->show($submission))
                ->allowAll()
                ->requirement('submission', '\d+')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.comment',
            RouteBuilder::create('/submissions/{submission}/review/comment')
                ->controller(fn (Request $request, string $submission) => $reviews->addComment($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.note',
            RouteBuilder::create('/submissions/{submission}/review/appendix/note')
                ->controller(fn (Request $request, string $submission) => $reviews->addAppendixNote($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.note.clear',
            RouteBuilder::create('/submissions/{submission}/review/appendix/note/clear')
                ->controller(fn (Request $request, string $submission) => $reviews->clearAppendixNote($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.note.restore',
            RouteBuilder::create('/submissions/{submission}/review/appendix/note/restore')
                ->controller(fn (Request $request, string $submission) => $reviews->restoreAppendixNote($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.status',
            RouteBuilder::create('/submissions/{submission}/review/status')
                ->controller(fn (Request $request, string $submission) => $reviews->updateStatus($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.send_back',
            RouteBuilder::create('/submissions/{submission}/review/send-back')
                ->controller(fn (Request $request, string $submission) => $reviews->sendBackToIntake($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.review',
            RouteBuilder::create('/submissions/{submission}/review/appendix/review')
                ->controller(fn (Request $request, string $submission) => $reviews->markAppendixReviewed($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.clear',
            RouteBuilder::create('/submissions/{submission}/review/appendix/clear')
                ->controller(fn (Request $request, string $submission) => $reviews->clearAppendixReviewed($request, $submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.review_all',
            RouteBuilder::create('/submissions/{submission}/review/appendix/review-all')
                ->controller(fn (Request $request, string $submission) => $reviews->markAllAppendicesReviewed($submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'submissions.review.appendix.clear_all',
            RouteBuilder::create('/submissions/{submission}/review/appendix/clear-all')
                ->controller(fn (Request $request, string $submission) => $reviews->clearAllAppendixReviews($submission))
                ->allowAll()
                ->csrfExempt()
                ->requirement('submission', '\d+')
                ->methods('POST')
                ->build(),
        );
    }

    /**
     * @return list<Command>
     */
    public function commands(
        EntityTypeManager $entityTypeManager,
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        return [
            new SeedNorthOpsCommand(
                $this->resolve(ProposalSchemaBootstrap::class),
                $this->resolve(NorthOpsSeedImporter::class),
            ),
        ];
    }
}
