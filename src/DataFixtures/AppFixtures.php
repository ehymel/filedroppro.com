<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->email = 'ehymel@oncologysupport.com';
        $user->roles = ['ROLE_SUPERUSER'];
        $user->isActivated = true;

        $manager->persist($user);
        $manager->flush();
    }
}
