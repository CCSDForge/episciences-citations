<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Intentionally empty: test fixtures live in tests/Fixtures (DocumentFixtures,
        // PaperReferencesFixtures, UserFixtures) and are loaded directly by the test suite.
    }
}
