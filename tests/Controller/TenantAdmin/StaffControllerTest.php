<?php

namespace App\Tests\Controller\TenantAdmin;

use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StaffControllerTest extends WebTestCase
{
    public function testInviteExistingEmailInDifferentTenant(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        // 1. Create Tenant A and an Admin for it
        $tenantA = new Tenant();
        $tenantA->firmName = 'Tenant A';
        $tenantA->status = 'active';
        $entityManager->persist($tenantA);

        $adminA = new User();
        $adminA->email = 'admin-a@example.com';
        $adminA->firstName = 'Admin';
        $adminA->lastName = 'A';
        $adminA->roles = ['ROLE_ADMIN'];
        $adminA->tenant = $tenantA;
        $adminA->password = 'password';
        $adminA->isActivated = true;
        $entityManager->persist($adminA);

        // 2. Create Tenant B and a User for it with a specific email
        $tenantB = new Tenant();
        $tenantB->firmName = 'Tenant B';
        $tenantB->status = 'active';
        $entityManager->persist($tenantB);

        $userB = new User();
        $userB->email = 'existing-user@example.com';
        $userB->firstName = 'User';
        $userB->lastName = 'B';
        $userB->tenant = $tenantB;
        $userB->password = 'password';
        $userB->isActivated = true;
        $entityManager->persist($userB);

        $entityManager->flush();

        // 3. Log in as Admin A
        $client->loginUser($adminA);

        // 4. Try to invite the email 'existing-user@example.com' to Tenant A
        // This should fail because the email already exists in Tenant B
        $crawler = $client->request('GET', '/internal/staff/invite');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send Invitation')->form([
            'invitation_form[email]' => 'existing-user@example.com',
        ]);

        $client->submit($form);

        // 5. Assert that we get an error saying the user already exists
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.invalid-feedback', 'already exists on the platform');
    }
}
