<?php

namespace App\Tests\Controller;

use App\Entity\Invitation;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the public user registration flow.
 *
 * The controller is tightly bound to the Symfony Form component, the
 * EntityManager and HTTP redirects, so these are functional tests driven
 * through the real HTTP kernel (matching the pattern used in
 * {@see \App\Tests\Controller\TenantAdmin\StaffControllerTest}).
 *
 * Each test runs inside a database transaction that is rolled back in
 * tearDown() so the test database is left untouched between runs. Reboot is
 * disabled so the request and the test share a single DB connection.
 */
class RegistrationControllerTest extends WebTestCase
{
    private const BUTTON = 'Register Account & Generate Keys';

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

    // ---------------------------------------------------------------------
    // New firm (tenant) registration
    // ---------------------------------------------------------------------

    public function testRegisterNewFirmCreatesActiveAdminAndTenant(): void
    {
        $email = $this->uniqueEmail();
        $firmName = 'Apex Clinic ' . uniqid();

        $crawler = $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton(self::BUTTON)->form(
            $this->newFirmValues($email, $firmName)
        );
        $this->client->submit($form);

        // A successful registration redirects to the login page.
        $this->assertResponseRedirects('/login');

        $user = $this->findUser($email);
        $this->assertNotNull($user, 'The new admin user should be persisted.');
        $this->assertSame(User::STATUS_ACTIVE, $user->status, 'Firm creators are active immediately.');
        $this->assertContains('ROLE_ADMIN', $user->roles);
        $this->assertSame('Jane', $user->firstName);
        $this->assertSame('Doe', $user->lastName);

        // A tenant is created and linked, with a generated join code.
        $this->assertNotNull($user->tenant);
        $this->assertSame($firmName, $user->tenant->firmName);
        $this->assertSame('active', $user->tenant->status);
        $this->assertStringStartsWith('TX-', (string) $user->tenant->joinCode);

        // The 14-day card-free trial window is established at registration.
        $this->assertSame('trial', $user->tenant->subscriptionPlan);
        $this->assertNotNull($user->tenant->currentPeriodEnd, 'The trial must have an end date.');
        $this->assertGreaterThan(new \DateTimeImmutable('+13 days'), $user->tenant->currentPeriodEnd);
        $this->assertLessThan(new \DateTimeImmutable('+15 days'), $user->tenant->currentPeriodEnd);
        $this->assertSame('tenant-public-key', $user->tenant->tenantPublicKey);
        $this->assertSame('recovery-wrapped-tenant-private-key', $user->tenant->recoveryWrappedPrivateKey);

        // The E2EE key material was persisted for the user.
        $this->assertNotNull($user->userKey);
        $this->assertSame('user-public-key', $user->userKey->publicKey);
        $this->assertSame('user-encrypted-private-key', $user->userKey->encryptedPrivateKey);

        // The submitted plain-text password was hashed, not stored verbatim.
        $this->assertNotNull($user->password);
        $this->assertNotSame('SuperSecretPass123', $user->password);
    }

    public function testRegisterNewFirmWithDuplicateNameIsRejected(): void
    {
        $firmName = 'Existing Firm ' . uniqid();
        $this->createTenant($firmName);

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton(self::BUTTON)->form(
            $this->newFirmValues($this->uniqueEmail(), $firmName)
        );
        $this->client->submit($form);

        // Invalid form submissions re-render with HTTP 422.
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'already registered on our platform');
    }

    public function testRegisterNewFirmRequiresE2eeKeys(): void
    {
        $values = $this->newFirmValues($this->uniqueEmail(), 'No Keys Firm ' . uniqid());
        // Simulate the browser failing to generate the user's public key.
        $values['registration_form[publicKey]'] = '';

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton(self::BUTTON)->form($values);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->findUser($values['registration_form[email]']));
    }

    // ---------------------------------------------------------------------
    // Joining an existing firm via join code
    // ---------------------------------------------------------------------

    public function testRegisterJoinExistingFirmCreatesPendingUser(): void
    {
        $tenant = $this->createTenant('Joinable Firm ' . uniqid());
        $tenant->joinCode = 'TX-' . strtoupper(bin2hex(random_bytes(4)));
        $this->em->flush();

        $email = $this->uniqueEmail();
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton(self::BUTTON)->form(
            $this->joinValues($email, $tenant->joinCode)
        );
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');

        $user = $this->findUser($email);
        $this->assertNotNull($user);
        $this->assertSame(User::STATUS_PENDING, $user->status, 'Joining users await admin approval.');
        $this->assertSame(['ROLE_USER'], $user->roles);
        $this->assertNotNull($user->tenant);
        $this->assertSame($tenant->firmName, $user->tenant->firmName);
    }

    public function testRegisterJoinIsCaseInsensitiveAndTrimsWhitespace(): void
    {
        $tenant = $this->createTenant('Trim Firm ' . uniqid());
        $tenant->joinCode = 'TX-' . strtoupper(bin2hex(random_bytes(4)));
        $this->em->flush();

        $email = $this->uniqueEmail();
        // Submit the same code padded with spaces and in lower case.
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton(self::BUTTON)->form(
            $this->joinValues($email, '  ' . strtolower($tenant->joinCode) . '  ')
        );
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');
        $this->assertNotNull($this->findUser($email));
    }

    public function testRegisterJoinWithInvalidCodeShowsError(): void
    {
        $email = $this->uniqueEmail();
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton(self::BUTTON)->form(
            $this->joinValues($email, 'TX-DOESNOTEXIST')
        );
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'invalid or does not match an active organization');
        $this->assertNull($this->findUser($email), 'No user should be created for an invalid join code.');
    }

    // ---------------------------------------------------------------------
    // Invitation token flow
    // ---------------------------------------------------------------------

    public function testRegisterWithValidInvitationCreatesPendingUserAndConsumesToken(): void
    {
        $tenant = $this->createTenant('Invited Firm ' . uniqid());
        $invitedEmail = $this->uniqueEmail();
        $invitation = $this->createInvitation($tenant, $invitedEmail, '+48 hours');
        $token = $invitation->token;

        $crawler = $this->client->request('GET', '/register?token=' . $token);
        $this->assertResponseIsSuccessful();

        // Email is locked (disabled) on invitation registrations, so it is not
        // part of the submitted form; the controller sourced it from the token.
        $form = $crawler->selectButton(self::BUTTON)->form($this->invitationValues());
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');

        $user = $this->findUser($invitedEmail);
        $this->assertNotNull($user);
        $this->assertSame($invitedEmail, $user->email);
        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertSame(['ROLE_USER'], $user->roles);
        $this->assertSame($tenant->firmName, $user->tenant->firmName);

        // The invitation is marked as consumed.
        $this->em->clear();
        $consumed = $this->em->getRepository(Invitation::class)->findOneBy(['token' => $token]);
        $this->assertNotNull($consumed);
        $this->assertTrue($consumed->used, 'The invitation token should be marked used.');
    }

    public function testRegisterWithInvalidTokenRedirectsBackToRegister(): void
    {
        $this->client->request('GET', '/register?token=totally-bogus-token');

        $this->assertResponseRedirects('/register');
    }

    public function testRegisterWithExpiredTokenRedirectsToLogin(): void
    {
        $tenant = $this->createTenant('Expired Firm ' . uniqid());
        $invitation = $this->createInvitation($tenant, $this->uniqueEmail(), '-1 hour');

        $this->client->request('GET', '/register?token=' . $invitation->token);

        $this->assertResponseRedirects('/login');
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function uniqueEmail(): string
    {
        return 'user_' . uniqid() . '@example.com';
    }

    private function createTenant(string $firmName): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = $firmName;
        $tenant->status = 'active';
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createInvitation(Tenant $tenant, string $email, string $expiresModifier): Invitation
    {
        $invitation = new Invitation();
        $invitation->tenant = $tenant;
        $invitation->email = $email;
        $invitation->token = bin2hex(random_bytes(16));
        $invitation->expiresAt = (new \DateTimeImmutable())->modify($expiresModifier);
        $invitation->used = false;
        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    private function findUser(string $email): ?User
    {
        // Detach persisted identity map so we read the flushed state fresh.
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * Base set of always-required form fields (name, password, terms, E2EE
     * user keys). Callers merge in the mode-specific fields.
     *
     * @return array<string, string|bool>
     */
    private function baseValues(string $email): array
    {
        return [
            'registration_form[email]' => $email,
            'registration_form[firstName]' => 'Jane',
            'registration_form[lastName]' => 'Doe',
            'registration_form[plainPassword][first]' => 'SuperSecretPass123',
            'registration_form[plainPassword][second]' => 'SuperSecretPass123',
            'registration_form[agreeTerms]' => true,
            'registration_form[publicKey]' => 'user-public-key',
            'registration_form[encryptedPrivateKey]' => 'user-encrypted-private-key',
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    private function newFirmValues(string $email, string $firmName): array
    {
        return $this->baseValues($email) + [
            'registration_form[registrationMode]' => 'new',
            'registration_form[firmName]' => $firmName,
            'registration_form[tenantPublicKey]' => 'tenant-public-key',
            'registration_form[wrappedTenantPrivateKey]' => 'wrapped-tenant-private-key',
            'registration_form[recoveryWrappedPrivateKey]' => 'recovery-wrapped-tenant-private-key',
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    private function joinValues(string $email, string $joinCode): array
    {
        // tenant key fields are validated (NotBlank) for every non-invitation
        // registration, even when joining, so they must be supplied.
        return $this->baseValues($email) + [
            'registration_form[registrationMode]' => 'join',
            'registration_form[joinCode]' => $joinCode,
            'registration_form[tenantPublicKey]' => 'tenant-public-key',
            'registration_form[wrappedTenantPrivateKey]' => 'wrapped-tenant-private-key',
        ];
    }

    /**
     * Invitation registrations expose neither the mode selector nor the tenant
     * key fields, and the email input is disabled (submitted from the token).
     *
     * @return array<string, string|bool>
     */
    private function invitationValues(): array
    {
        $values = $this->baseValues('unused@example.com');
        unset($values['registration_form[email]']);

        return $values;
    }
}
