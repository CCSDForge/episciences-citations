<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaperReferences;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Shared reference id lookup and source/DOI filtering for the *-enrich-references commands.
 *
 * Requires the composing class to expose a `private readonly EntityManagerInterface $entityManager`.
 */
trait ReferenceIdFilterTrait
{
    /**
     * @return array<int, int>
     */
    private function getReferenceIds(InputInterface $input): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(PaperReferences::class, 'p')
            ->orderBy('p.id', 'ASC');

        if ($input->getOption('docid') !== null) {
            $queryBuilder
                ->andWhere('p.document = :docId')
                ->setParameter('docId', (int) $input->getOption('docid'));
        }

        if ($input->getOption('source') !== null) {
            $queryBuilder
                ->andWhere('p.source = :source')
                ->setParameter('source', $input->getOption('source'));
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $queryBuilder->getQuery()->getArrayResult()
        );
    }

    private function isValidSource(string $source): bool
    {
        return in_array($source, [
            PaperReferences::SOURCE_METADATA_GROBID,
            PaperReferences::SOURCE_METADATA_EPI_USER,
            PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT,
            PaperReferences::SOURCE_SEMANTICS_SCHOLAR,
        ], true);
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function hasDoi(array $reference): bool
    {
        return isset($reference['doi']) && is_string($reference['doi']) && trim($reference['doi']) !== '';
    }
}
