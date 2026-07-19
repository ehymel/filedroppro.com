<?php

namespace App\Tests\Controller\TenantAdmin;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\StripeBillingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for {@see \App\Controller\TenantAdmin\BillingController}.
 *
 * Driven through the real HTTP kernel following the pattern used by the other
 * controller tests: each test runs inside a DB transaction rolled back in
 * tearDown(), with reboot disabled so the request and the test share one
 * kernel, DB connection and EntityManager.
 *
 * StripeBillingService is replaced with an in-memory fake so no Stripe API
 * calls are made and checkout/portal URLs and failures can be controlled.
 */
class BillingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeStripeBillingService $stripe;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->stripe = new FakeStripeBillingService();
        static::getContainer()->set(StripeBillingService::class, $this->stripe);

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
    // Access control
    // ---------------------------------------------------------------------

    public function testDashboardIsForbiddenForNonAdmins(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER']);

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/billing/');

        $this->assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------------
    // dashboard()
    // ---------------------------------------------------------------------

    public function testDashboardRendersAndReconcilesSubscriptionStatus(): void
    {
        $tenant = $this->createTenant('active', 'trial');
        $tenant->currentPeriodEnd = new \DateTimeImmutable('+10 days');
        $this->em->flush();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/internal/billing/');

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->stripe->syncCalled, 'The dashboard must reconcile status with Stripe on load.');
        $this->assertSame($tenant->id->toString(), $this->stripe->syncedTenant?->id->toString());
        $this->assertStringContainsString($tenant->firmName, $crawler->filter('body')->text());
    }

    // ---------------------------------------------------------------------
    // subscribe()
    // ---------------------------------------------------------------------

    public function testSubscribeRejectsInvalidCsrfToken(): void
    {
        $admin = $this->createUser($this->createTenant(), ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => 'not-a-valid-token',
            'plan' => 'basic',
        ]);

        $this->assertResponseRedirects('/internal/billing/');
    }

    public function testSubscribeRejectsAnUnknownPlan(): void
    {
        $admin = $this->createUser($this->createTenant(), ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/subscribe');
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => $token,
            'plan' => 'unobtainium',
        ]);

        $this->assertResponseRedirects('/internal/billing/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid plan configuration selected.');
    }

    public function testSubscribeToTrialActivatesCardFreeTrial(): void
    {
        $tenant = $this->createTenant('active', 'basic');
        $admin = $this->createUser($tenant, ['ROLE_ADMIN']);
        $tenantId = $tenant->id->toString();

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/subscribe');
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => $token,
            'plan' => 'trial',
        ]);

        $this->assertResponseRedirects('/internal/billing/');

        $this->em->clear();
        $fresh = $this->em->getRepository(Tenant::class)->find($tenantId);
        $this->assertSame('trial', $fresh->subscriptionPlan);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->stripeSubscriptionId);
        $this->assertNotNull($fresh->currentPeriodEnd);
        $this->assertGreaterThan(new \DateTimeImmutable('+13 days'), $fresh->currentPeriodEnd);
    }

    public function testSubscribeFirstTimeRedirectsToStripeCheckout(): void
    {
        // No existing Stripe subscription -> standard checkout path.
        $admin = $this->createUser($this->createTenant('active', 'basic'), ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/subscribe');
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => $token,
            'plan' => 'basic',
        ]);

        $this->assertResponseRedirects($this->stripe->checkoutUrl);
        $this->assertSame(
            $_ENV['STRIPE_PRICE_BASIC'],
            $this->stripe->lastPriceId,
            'The configured Stripe price for the chosen plan must be used.'
        );
    }

    public function testSubscribeForExistingSubscriberOpensPortalUpgradeFlow(): void
    {
        $tenant = $this->createTenant('active', 'basic');
        $tenant->stripeCustomerId = 'cus_test_123';
        $tenant->stripeSubscriptionId = 'sub_test_456';
        $this->em->flush();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/subscribe');
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => $token,
            'plan' => 'pro',
        ]);

        $this->assertResponseRedirects($this->stripe->portalUrl);
        $this->assertSame('subscription_update', $this->stripe->lastPortalFlowData['type'] ?? null);
        $this->assertSame('sub_test_456', $this->stripe->lastPortalFlowData['subscription_update']['subscription'] ?? null);
    }

    public function testSubscribeShowsErrorWhenCheckoutCreationFails(): void
    {
        $admin = $this->createUser($this->createTenant('active', 'basic'), ['ROLE_ADMIN']);
        $this->stripe->throwOnCheckout = new \RuntimeException('gateway down');

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/subscribe');
        $this->client->request('POST', '/internal/billing/subscribe', [
            '_token' => $token,
            'plan' => 'basic',
        ]);

        $this->assertResponseRedirects('/internal/billing/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Failed to initialize payment gateway');
    }

    // ---------------------------------------------------------------------
    // openPortal()
    // ---------------------------------------------------------------------

    public function testPortalRejectsInvalidCsrfToken(): void
    {
        $admin = $this->createUser($this->createTenant(), ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/internal/billing/portal', ['_token' => 'not-a-valid-token']);

        $this->assertResponseRedirects('/internal/billing/');
    }

    public function testPortalRedirectsToStripeCustomerPortal(): void
    {
        $tenant = $this->createTenant('active', 'pro');
        // Both IDs are required for the portal button (and its token) to render.
        $tenant->stripeCustomerId = 'cus_test_123';
        $tenant->stripeSubscriptionId = 'sub_test_456';
        $this->em->flush();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/portal');
        $this->client->request('POST', '/internal/billing/portal', ['_token' => $token]);

        $this->assertResponseRedirects($this->stripe->portalUrl);
    }

    public function testPortalShowsErrorWhenPortalCreationFails(): void
    {
        $tenant = $this->createTenant('active', 'pro');
        $tenant->stripeCustomerId = 'cus_test_123';
        $tenant->stripeSubscriptionId = 'sub_test_456';
        $this->em->flush();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN']);
        $this->stripe->throwOnPortal = new \RuntimeException('portal down');

        $this->client->loginUser($admin);
        $token = $this->tokenForForm('/portal');
        $this->client->request('POST', '/internal/billing/portal', ['_token' => $token]);

        $this->assertResponseRedirects('/internal/billing/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Failed to load self-service portal');
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function createTenant(string $status = 'active', string $plan = 'pro'): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = $status;
        $tenant->subscriptionPlan = $plan;
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * @param array<int, string> $roles
     */
    private function createUser(Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->email = 'user_' . uniqid() . '@example.com';
        $user->firstName = 'Test';
        $user->lastName = 'Admin';
        $user->roles = $roles;
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = User::STATUS_ACTIVE;
        $user->isActivated = true;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Loads the billing dashboard and returns the stateful CSRF token embedded
     * in the form whose action ends with $actionSuffix. Because the token is
     * generated inside the same session the client carries, the subsequent POST
     * validates against it, exactly as it would in the browser.
     */
    private function tokenForForm(string $actionSuffix): string
    {
        $crawler = $this->client->request('GET', '/internal/billing/');
        $this->assertResponseIsSuccessful();

        $input = $crawler->filter('form[action$="' . $actionSuffix . '"] input[name="_token"]');
        $this->assertGreaterThan(0, $input->count(), 'No form found for action ' . $actionSuffix);

        return (string) $input->first()->attr('value');
    }
}

/**
 * In-memory stand-in for StripeBillingService. The parent constructor (which
 * builds a real StripeClient) is skipped, and every outward-facing method is
 * overridden so tests can control return URLs / failures and assert on inputs.
 */
class FakeStripeBillingService extends StripeBillingService
{
    public bool $syncCalled = false;
    public ?Tenant $syncedTenant = null;
    public string $checkoutUrl = 'https://stripe.test/checkout/session_abc';
    public string $portalUrl = 'https://stripe.test/portal/session_xyz';
    public ?string $lastPriceId = null;
    public ?array $lastPortalFlowData = null;
    public ?\Throwable $throwOnCheckout = null;
    public ?\Throwable $throwOnPortal = null;

    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }

    public function syncSubscriptionStatus(Tenant $tenant): void
    {
        $this->syncCalled = true;
        $this->syncedTenant = $tenant;
    }

    public function createCheckoutSession(User $admin, string $priceId, ?int $trialDays = null): string
    {
        if ($this->throwOnCheckout) {
            throw $this->throwOnCheckout;
        }
        $this->lastPriceId = $priceId;

        return $this->checkoutUrl;
    }

    public function createPortalSession(Tenant $tenant, ?array $flowData = null): string
    {
        if ($this->throwOnPortal) {
            throw $this->throwOnPortal;
        }
        $this->lastPortalFlowData = $flowData;

        return $this->portalUrl;
    }
}
