<?php

namespace App\Tests\Fixtures;

use App\Entity\Document;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DocumentFixtures extends Fixture implements DependentFixtureInterface
{
    public const DOC_1_REFERENCE = 'document-123456';
    public const DOC_2_REFERENCE = 'document-789012';
    public const DOC_3_REFERENCE = 'document-333333';

    public function load(ObjectManager $manager): void
    {
        // Document 1 - Avec références acceptées et non acceptées
        $doc1 = new Document();
        $doc1->setId(123456);
        $manager->persist($doc1);
        $this->addReference(self::DOC_1_REFERENCE, $doc1);

        // Document 2 - Avec références mixtes
        $doc2 = new Document();
        $doc2->setId(789012);
        $manager->persist($doc2);
        $this->addReference(self::DOC_2_REFERENCE, $doc2);

        // Document 3 - Vide (sans références)
        $doc3 = new Document();
        $doc3->setId(333333);
        $manager->persist($doc3);
        $this->addReference(self::DOC_3_REFERENCE, $doc3);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [];
    }
}
