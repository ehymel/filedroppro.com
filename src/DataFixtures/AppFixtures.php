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
        $user->username = 'ehymel';
        $user->password = '$2y$13$u0bvX5hd9Kvj1m.ntMWQQeZTUMhH5Vw1IkPVXC2TBPPBDHPPNptPS';
        $user->firstName = 'Ernest';
        $user->lastName = 'Hymel';
        $user->roles = ['ROLE_SUPERUSER'];
        $user->email = 'ehymel@oncologysupport.com';
        $user->isActivated = true;

        $manager->persist($user);
        $manager->flush();
    }
}
