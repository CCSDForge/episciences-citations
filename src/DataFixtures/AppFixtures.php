<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\PaperReferences;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {

    }
}
