<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StripeBillingService
{
    private StripeClient $stripe;

    public function __construct(
        #[Autowire(param: 'env(STRIPE_SECRET_KEY)')] string $stripeSecretKey,
        private readonly UrlGeneratorInterface $router
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    /**
     * Spawns a pre-configured Checkout Session for subscribing a Tenant.
     * @throws ApiErrorException
     */
    public function createCheckoutSession(User $admin, string $priceId): string
    {
        $tenant = $admin->tenant;

        // If the tenant already has a Stripe Customer ID, reuse it to prevent duplicate accounts
        $customerParams = [];
        if ($tenant->stripeCustomerId) {
            $customerParams['customer'] = $tenant->stripeCustomerId;
        } else {
            $customerParams['customer_email'] = $admin->email;
        }

        $session = $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'metadata' => [
                'tenant_id' => $tenant->id->toString() // Crucial for mapping webhooks back to database rows!
            ],
            ...$customerParams
        ]);

        return $session->url;
    }

    /**
     * Spawns a hosted self-service Customer Portal session.
     * @throws ApiErrorException
     */
    public function createPortalSession(Tenant $tenant): string
    {
        if (!$tenant->stripeCustomerId) {
            throw new \LogicException('Tenant is not associated with a Stripe customer account yet.');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $tenant->stripeCustomerId,
            'return_url' => $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $session->url;
    }
}
