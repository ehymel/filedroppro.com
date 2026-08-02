<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\UnsubscribeLinkGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the signed, unauthenticated opt-out links carried in the onboarding drip.
 *
 * The security property under test is that authorisation comes from the URL signature
 * alone: a valid signature opts exactly one recipient out, and anything unsigned or
 * altered is refused outright.
 */
class EmailPreferencesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    public function testSignedLinkRendersConfirmationWithoutUnsubscribing(): void
    {
        $user = $this->createUser($this->createTenant());

        $crawler = $this->client->request('GET', $this->signedUrl($user));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[method="POST"]');
        $this->assertStringContainsString($user->email, $crawler->text());

        // A GET must never mutate: link scanners prefetch these.
        $this->em->refresh($user);
        $this->assertNull($user->onboardingUnsubscribedAt);
    }

    public function testConfirmingUnsubscribesTheRecipient(): void
    {
        $user = $this->createUser($this->createTenant());

        $this->client->request('POST', $this->signedUrl($user));

        $this->assertResponseIsSuccessful();

        $this->em->refresh($user);
        $this->assertNotNull($user->onboardingUnsubscribedAt);
    }

    public function testRepeatedUnsubscribeKeepsTheOriginalTimestamp(): void
    {
        $user = $this->createUser($this->createTenant());
        $path = $this->signedUrl($user);

        $this->client->request('POST', $path);
        $this->em->refresh($user);
        $firstTimestamp = $user->onboardingUnsubscribedAt;

        $this->client->request('POST', $path);
        $this->assertResponseIsSuccessful();

        $this->em->refresh($user);
        $this->assertEquals($firstTimestamp, $user->onboardingUnsubscribedAt);
    }

    public function testUnsignedLinkIsRefused(): void
    {
        $user = $this->createUser($this->createTenant());

        // Same host as a genuine link, so the only thing missing is the signature.
        $this->client->request('GET', $this->unsignedUrl($user));

        $this->assertResponseStatusCodeSame(403);

        $this->em->refresh($user);
        $this->assertNull($user->onboardingUnsubscribedAt);
    }

    public function testAlteredRecipientIdIsRefused(): void
    {
        $tenant = $this->createTenant();
        $victim = $this->createUser($tenant);
        $attacker = $this->createUser($tenant);

        // Take a legitimately signed link and repoint it at somebody else.
        $tampered = str_replace(
            (string) $attacker->id,
            (string) $victim->id,
            $this->signedUrl($attacker)
        );

        $this->client->request('POST', $tampered);

        $this->assertResponseStatusCodeSame(403);

        $this->em->refresh($victim);
        $this->assertNull($victim->onboardingUnsubscribedAt);
    }

    /**
     * Requests are made against the absolute URL on purpose: the signature is computed over
     * scheme and host as well as the path, so hitting the same route on a different host
     * would fail the check for reasons unrelated to what each test is asserting.
     */
    private function signedUrl(User $user): string
    {
        return static::getContainer()->get(UnsubscribeLinkGenerator::class)->generate($user);
    }

    private function unsignedUrl(User $user): string
    {
        return strtok($this->signedUrl($user), '?');
    }

    private function createTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = 'active';
        $tenant->subscriptionPlan = 'trial';
        $tenant->currentPeriodEnd = new \DateTimeImmutable('+14 days');
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createUser(Tenant $tenant): User
    {
        $user = new User();
        $user->email = 'admin_' . uniqid() . '@example.com';
        $user->firstName = 'Test';
        $user->lastName = 'Admin';
        $user->roles = ['ROLE_ADMIN'];
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = User::STATUS_ACTIVE;
        $user->isActivated = true;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
