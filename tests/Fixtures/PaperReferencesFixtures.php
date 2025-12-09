<?php

namespace App\Tests\Fixtures;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class PaperReferencesFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Document $doc1 */
        $doc1 = $this->getReference(DocumentFixtures::DOC_1_REFERENCE);
        /** @var Document $doc2 */
        $doc2 = $this->getReference(DocumentFixtures::DOC_2_REFERENCE);
        /** @var UserInformations $user1 */
        $user1 = $this->getReference(UserFixtures::USER_1_REFERENCE);
        /** @var UserInformations $user2 */
        $user2 = $this->getReference(UserFixtures::USER_2_REFERENCE);

        // Document 1 : Références acceptées (source GROBID)
        $ref1 = new PaperReferences();
        $ref1->setReference([json_encode([
            'raw_reference' => 'John Doe et al. Test Article. Test Journal, 2024.',
            'doi' => '10.1234/test1',
            'csl' => [
                'type' => 'article-journal',
                'title' => 'Test Article',
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
                'container-title' => 'Test Journal'
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref1->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref1->setAccepted(1);
        $ref1->setReferenceOrder(0);
        $ref1->setDocument($doc1);
        $ref1->setUid($user1);
        $ref1->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref1);

        // Document 1 : Référence acceptée (source USER - modifiée par utilisateur)
        $ref2 = new PaperReferences();
        $ref2->setReference([json_encode([
            'raw_reference' => 'Jane Smith. Modified Reference. Custom Journal, 2023.',
            'doi' => '10.5678/test2'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref2->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
        $ref2->setAccepted(1);
        $ref2->setReferenceOrder(1);
        $ref2->setDocument($doc1);
        $ref2->setUid($user2);
        $ref2->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref2);

        // Document 1 : Référence acceptée (source USER)
        $ref3 = new PaperReferences();
        $ref3->setReference([json_encode([
            'raw_reference' => 'Another Author. Another Article. Journal, 2022.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref3->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
        $ref3->setAccepted(1);
        $ref3->setReferenceOrder(2);
        $ref3->setDocument($doc1);
        $ref3->setUid($user1);
        $ref3->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref3);

        // Document 1 : Référence NON acceptée (source GROBID)
        $ref4 = new PaperReferences();
        $ref4->setReference([json_encode([
            'raw_reference' => 'Unvalidated Reference 1. Unknown Journal, 2021.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref4->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref4->setAccepted(0);
        $ref4->setReferenceOrder(3);
        $ref4->setDocument($doc1);
        $ref4->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref4);

        // Document 1 : Référence NON acceptée (source GROBID)
        $ref5 = new PaperReferences();
        $ref5->setReference([json_encode([
            'raw_reference' => 'Unvalidated Reference 2. Unknown Source, 2020.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref5->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref5->setAccepted(0);
        $ref5->setReferenceOrder(4);
        $ref5->setDocument($doc1);
        $ref5->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref5);

        // Document 2 : Références importées depuis BibTeX
        $ref6 = new PaperReferences();
        $ref6->setReference([json_encode([
            'raw_reference' => 'BibTeX Import 1. From File Import, 2023.',
            'doi' => '10.9999/import1',
            'csl' => [
                'type' => 'book',
                'title' => 'Imported Book',
                'author' => [['family' => 'Author', 'given' => 'Test']],
                'issued' => ['date-parts' => [[2023]]],
                'publisher' => 'Test Publisher'
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref6->setSource(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);
        $ref6->setAccepted(1);
        $ref6->setReferenceOrder(0);
        $ref6->setDocument($doc2);
        $ref6->setUid($user1);
        $ref6->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref6);

        // Document 2 : Référence BibTeX import (non acceptée)
        $ref7 = new PaperReferences();
        $ref7->setReference([json_encode([
            'raw_reference' => 'BibTeX Import 2. Pending Review, 2022.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref7->setSource(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);
        $ref7->setAccepted(0);
        $ref7->setReferenceOrder(1);
        $ref7->setDocument($doc2);
        $ref7->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref7);

        // Document 2 : Référence Semantic Scholar
        $ref8 = new PaperReferences();
        $ref8->setReference([json_encode([
            'raw_reference' => 'Semantic Scholar Reference. AI Journal, 2024.',
            'doi' => '10.1111/s2test'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref8->setSource(PaperReferences::SOURCE_METADATA_SEMANTICS);
        $ref8->setAccepted(1);
        $ref8->setReferenceOrder(2);
        $ref8->setDocument($doc2);
        $ref8->setUid($user2);
        $ref8->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref8);

        // Document 2 : Référence sans CSL
        $ref9 = new PaperReferences();
        $ref9->setReference([json_encode([
            'raw_reference' => 'Reference without CSL. Simple Text, 2023.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref9->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref9->setAccepted(1);
        $ref9->setReferenceOrder(3);
        $ref9->setDocument($doc2);
        $ref9->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref9);

        // Document 2 : Référence avec DOI mais sans CSL
        $ref10 = new PaperReferences();
        $ref10->setReference([json_encode([
            'raw_reference' => 'DOI only reference. Test, 2021.',
            'doi' => '10.7777/doi-only'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        $ref10->setSource(PaperReferences::SOURCE_METADATA_GROBID);
        $ref10->setAccepted(0);
        $ref10->setReferenceOrder(4);
        $ref10->setDocument($doc2);
        $ref10->setUpdatedAt(new \DateTimeImmutable());
        $manager->persist($ref10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            DocumentFixtures::class,
            UserFixtures::class,
        ];
    }
}
