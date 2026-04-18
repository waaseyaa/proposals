<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class ArtifactAuditService
{
    public function __construct(
        private readonly EntityStorageInterface $documentStorage,
    ) {}

    /**
     * @return array{ready_count:int,total_count:int,missing:list<string>,items:list<array{document_type:string,label:string,ready:bool}>}
     */
    public function summarize(EntityInterface $submission): array
    {
        $expected = [
            'appendix_a_preview' => 'Appendix A HTML',
            'appendix_b_preview' => 'Appendix B HTML',
            'appendix_f_preview' => 'Appendix F HTML',
            'appendix_g_preview' => 'Appendix G HTML',
            'appendix_h_preview' => 'Appendix H HTML',
            'appendix_m_preview' => 'Appendix M HTML',
            'merged_package_preview' => 'Merged Package HTML',
            'merged_package_pdf' => 'Merged Package PDF',
            'artifact_bundle_zip' => 'Artifact Bundle ZIP',
        ];

        $ids = $this->documentStorage->getQuery()->execute();
        $documents = array_filter(
            $this->documentStorage->loadMultiple($ids),
            static fn (EntityInterface $document): bool => (int) ($document->get('submission_id') ?? 0) === (int) $submission->id(),
        );
        $available = [];

        foreach ($documents as $document) {
            $documentType = (string) ($document->get('document_type') ?? '');
            $path = (string) ($document->get('storage_path') ?? '');
            $available[$documentType] = $path !== '' && is_file($path);
        }

        $items = [];
        $missing = [];
        $readyCount = 0;
        foreach ($expected as $documentType => $label) {
            $ready = (bool) ($available[$documentType] ?? false);
            if ($ready) {
                $readyCount++;
            } else {
                $missing[] = $label;
            }

            $items[] = [
                'document_type' => $documentType,
                'label' => $label,
                'ready' => $ready,
            ];
        }

        return [
            'ready_count' => $readyCount,
            'total_count' => count($expected),
            'missing' => $missing,
            'items' => $items,
        ];
    }
}
