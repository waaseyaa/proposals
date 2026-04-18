<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use App\Domain\Import\NorthOpsSeedImporter;
use App\Support\ProposalSchemaBootstrap;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'proposals:seed-northops',
    description: 'Seed the Waaseyaa Proposals app with the latest NorthOps ISET package.',
)]
final class SeedNorthOpsCommand extends Command
{
    public function __construct(
        private readonly ProposalSchemaBootstrap $schemaBootstrap,
        private readonly NorthOpsSeedImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'source',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to the NorthOps source directory',
            '/home/fsd42/NorthOps',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getOption('source');

        if (!is_dir($source)) {
            $output->writeln(sprintf('<error>Source directory not found: %s</error>', $source));
            return self::FAILURE;
        }

        $this->schemaBootstrap->ensure();
        $result = $this->importer->import($source);

        $output->writeln('<info>NorthOps proposal seed complete.</info>');
        $output->writeln(sprintf('Pipeline ID: %s', (string) ($result['pipeline_id'] ?? 'n/a')));
        $output->writeln(sprintf('Cohort ID: %s', (string) ($result['cohort_id'] ?? 'n/a')));
        $output->writeln(sprintf('Submission ID: %s', (string) ($result['submission_id'] ?? 'n/a')));

        return self::SUCCESS;
    }
}
