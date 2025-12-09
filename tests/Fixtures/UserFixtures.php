<?php

namespace App\Tests\Fixtures;

use App\Entity\UserInformations;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class UserFixtures extends Fixture
{
    public const USER_1_REFERENCE = 'user-1001';
    public const USER_2_REFERENCE = 'user-2002';

    public function load(ObjectManager $manager): void
    {
        // User 1 - John Doe
        $user1 = new UserInformations();
        $user1->setId(1001);
        $user1->setName('Doe');
        $user1->setSurname('John');
        $manager->persist($user1);
        $this->addReference(self::USER_1_REFERENCE, $user1);

        // User 2 - Jane Smith
        $user2 = new UserInformations();
        $user2->setId(2002);
        $user2->setName('Smith');
        $user2->setSurname('Jane');
        $manager->persist($user2);
        $this->addReference(self::USER_2_REFERENCE, $user2);

        $manager->flush();
    }
}
