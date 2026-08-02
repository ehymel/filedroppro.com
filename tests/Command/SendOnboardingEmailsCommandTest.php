<?php

namespace App\Tests\Command;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Covers how app:send-onboarding-emails reacts to recipients who have opted out.
 *
 * The regression these tests exist for: the milestone pointer (Tenant::$lastOnboardingDaySent)
 * is normally only advanced once an email is actually away. If a workspace where every
 * administrator has unsubscribed left the pointer untouched, the command would re-evaluate
 * and re-warn about that workspace on every single run, forever.
 *
 * Tenants are given a currentPeriodEnd of +3 days, which puts them at 11 days elapsed on a
 * 14-day trial — the Day 11 milestone.
 */
class SendOnboardingEmailsCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const DAY_11_MILESTONE = 11;

    private EntityManagerInterface $em;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->getConnection()->beginTransaction();

        $application = new Application(static::$kernel);
        $this->commandTester = new CommandTester($application->find('app:send-onboarding-emails'));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUnsubscribedAdminIsSkippedWhileCoAdminStillReceives(): void
    {
        $tenant = $this->createTrialTenant();
        $subscribed = $this->createAdmin($tenant);
        $optedOut = $this->createAdmin($tenant, new \DateTimeImmutable('-1 day'));

        $this->commandTester->execute([]);

        $this->assertTrue($this->wasEmailed($subscribed), 'Subscribed administrator should receive the milestone.');
        $this->assertFalse($this->wasEmailed($optedOut), 'Unsubscribed administrator must be skipped.');

        $this->em->refresh($tenant);
        $this->assertSame(self::DAY_11_MILESTONE, $tenant->lastOnboardingDaySent);
    }

    /**
     * The regression guard: nobody is emailable, but the milestone must still be recorded
     * so the workspace is not reconsidered on every subsequent run.
     */
    public function testMilestoneIsRecordedWhenEveryAdminHasUnsubscribed(): void
    {
        $tenant = $this->createTrialTenant();
        $optedOut = $this->createAdmin($tenant, new \DateTimeImmutable('-1 day'));

        $this->commandTester->execute([]);

        $this->assertFalse($this->wasEmailed($optedOut));

        $this->em->refresh($tenant);
        $this->assertSame(
            self::DAY_11_MILESTONE,
            $tenant->lastOnboardingDaySent,
            'A milestone nobody can receive is still handled, otherwise the workspace is retried daily.'
        );
    }

    /**
     * A workspace with no administrator at all is a different case: that is a data problem
     * which may resolve, so the milestone must stay unrecorded and be retried.
     */
    public function testMilestoneIsNotRecordedWhenWorkspaceHasNoAdmin(): void
    {
        $tenant = $this->createTrialTenant();
        $this->createAdmin($tenant, null, ['ROLE_USER']);

        $this->commandTester->execute([]);

        $this->em->refresh($tenant);
        $this->assertNull($tenant->lastOnboardingDaySent);
    }

    public function testSentEmailCarriesUnsubscribeLinkAndOneClickHeaders(): void
    {
        $tenant = $this->createTrialTenant();
        $admin = $this->createAdmin($tenant);

        $this->commandTester->execute([]);

        $message = $this->messageFor($admin);
        $this->assertNotNull($message, 'Expected a milestone email for the subscribed administrator.');

        $headers = $message->getHeaders();
        $this->assertTrue($headers->has('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());

        $unsubscribeUrl = trim($headers->get('List-Unsubscribe')->getBodyAsString(), '<>');
        $this->assertStringContainsString('/email/unsubscribe/' . $admin->id, $unsubscribeUrl);
        $this->assertStringContainsString('_hash=', $unsubscribeUrl, 'The link must be signed.');

        // The same link has to reach the reader, not just the headers.
        $this->assertStringContainsString($unsubscribeUrl, $message->getHtmlBody());
    }

    private function wasEmailed(User $user): bool
    {
        return $this->messageFor($user) !== null;
    }

    /**
     * Matches on recipient rather than asserting a total count, so the assertions hold even
     * if the test database contains other trial workspaces the command also picks up.
     *
     * The logger listener runs at priority -255, after the body renderer, so what it captured
     * is the fully rendered message rather than an unresolved template.
     */
    private function messageFor(User $user): ?Email
    {
        foreach ($this->getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $user->email) {
                    return $message;
                }
            }
        }

        return null;
    }

    /**
     * A card-free trial sitting on the Day 11 milestone. No stripeCustomerId, so
     * StripeBillingService::syncSubscriptionStatus() returns without calling out to Stripe.
     */
    private function createTrialTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = 'active';
        $tenant->subscriptionPlan = 'trial';
        $tenant->currentPeriodEnd = new \DateTimeImmutable('+3 days');
        $tenant->lastOnboardingDaySent = null;
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createAdmin(Tenant $tenant, ?\DateTimeImmutable $unsubscribedAt = null, array $roles = ['ROLE_ADMIN']): User
    {
        $user = new User();
        $user->email = 'admin_' . uniqid() . '@example.com';
        $user->firstName = 'Test';
        $user->lastName = 'Admin';
        $user->roles = $roles;
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = User::STATUS_ACTIVE;
        $user->isActivated = true;
        $user->onboardingUnsubscribedAt = $unsubscribedAt;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
