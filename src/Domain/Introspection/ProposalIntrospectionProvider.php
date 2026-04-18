<?php

declare(strict_types=1);

namespace App\Domain\Introspection;

use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

final class ProposalIntrospectionProvider implements GraphSectionProviderInterface
{
    public function getKey(): string
    {
        return 'proposals';
    }

    public function provide(): GraphSection
    {
        return new GraphSection(
            key: 'proposals',
            version: '1.0',
            data: [
                'bounded_contexts' => [
                    'pipeline',
                    'submission',
                    'generation',
                    'review',
                ],
                'entity_types' => [
                    'proposal_pipeline',
                    'proposal_submission',
                    'proposal_document',
                    'proposal_review',
                    'proposal_cohort',
                ],
                'first_pipeline' => 'iset_self_employment',
                'appendices_targeted' => ['A', 'B', 'F', 'G', 'H', 'M'],
                'current_stage' => 'foundation_scaffolded',
            ],
        );
    }
}
