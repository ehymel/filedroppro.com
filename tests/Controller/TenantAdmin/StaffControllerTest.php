<?php

namespace App\Tests\Controller\TenantAdmin;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StaffControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one kernel (and one DB connection) alive across the GET + POST
        // so the manually opened transaction wraps the controller's flush().
        $this->client->disableReboot();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testInviteExistingEmailInDifferentTenant(): void
    {
        $suffix = uniqid();

        // 1. Create Tenant A and an Admin for it
        $tenantA = new Tenant();
        $tenantA->firmName = 'Tenant A ' . $suffix;
        $tenantA->status = 'active';
        $this->em->persist($tenantA);

        $adminA = new User();
        $adminA->email = 'admin-a-' . $suffix . '@example.com';
        $adminA->firstName = 'Admin';
        $adminA->lastName = 'A';
        $adminA->roles = ['ROLE_ADMIN'];
        $adminA->tenant = $tenantA;
        $adminA->password = 'password';
        $adminA->isActivated = true;
        $this->em->persist($adminA);

        // 2. Create Tenant B and a User for it with a specific email
        $tenantB = new Tenant();
        $tenantB->firmName = 'Tenant B ' . $suffix;
        $tenantB->status = 'active';
        $this->em->persist($tenantB);

        $existingEmail = 'existing-user-' . $suffix . '@example.com';
        $userB = new User();
        $userB->email = $existingEmail;
        $userB->firstName = 'User';
        $userB->lastName = 'B';
        $userB->tenant = $tenantB;
        $userB->password = 'password';
        $userB->isActivated = true;
        $this->em->persist($userB);

        $this->em->flush();

        // 3. Log in as Admin A
        $this->client->loginUser($adminA);

        // 4. Try to invite the email of the user that already exists in Tenant B.
        // This should fail because the email already exists on the platform.
        $crawler = $this->client->request('GET', '/internal/staff/invite');
        $this->assertResponseIsSuccessful();

        // The GET route renders only the bare form fragment; the visible
        // "Send Invitation" submit button lives in the modal wrapper, so we
        // build the request from the <form> node directly rather than a button.
        $form = $crawler->filter('form[name="invitation_form"]')->form([
            'invitation_form[email]' => $existingEmail,
        ]);

        $this->client->submit($form);

        // 5. Assert that we get an error saying the user already exists
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.invalid-feedback', 'already exists on the platform');
    }
}
