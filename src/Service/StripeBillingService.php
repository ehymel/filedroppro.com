<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StripeBillingService
{
    private StripeClient $stripe;

    public function __construct(
        #[Autowire(param: 'env(STRIPE_SECRET_KEY)')] string $stripeSecretKey,
        private readonly UrlGeneratorInterface $router,
        private readonly TenantRepository $tenantRepository
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    /**
     * Spawns a pre-configured Checkout Session for subscribing a Tenant.
     * @throws ApiErrorException
     */
    public function createCheckoutSession(User $admin, string $priceId, ?int $trialDays = null): string
    {
        $tenant = $admin->tenant;

        // If the tenant already has a Stripe Customer ID, reuse it to prevent duplicate accounts
        $customerParams = [];
        if ($tenant->stripeCustomerId) {
            $customerParams['customer'] = $tenant->stripeCustomerId;
        } else {
            $customerParams['customer_email'] = $admin->email;
        }

        // Configure optional dynamic trial parameters
        $subscriptionData = [];
        if ($trialDays !== null && $trialDays > 0) {
            $subscriptionData['subscription_data'] = [
                'trial_period_days' => $trialDays
            ];
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
            ...$subscriptionData,
            ...$customerParams
        ]);

        return $session->url;
    }

    /**
     * Spawns a hosted self-service Customer Portal session.
     * @throws ApiErrorException
     */
    public function createPortalSession(Tenant $tenant, ?array $flowData = null): string
    {
        if (!$tenant->stripeCustomerId) {
            throw new \LogicException('Tenant is not associated with a Stripe customer account yet.');
        }

        $params = [
            'customer' => $tenant->stripeCustomerId,
            'return_url' => $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        // Inject deep-linked action flow parameters (such as subscription_update) if supplied
        if ($flowData !== null) {
            $params['flow_data'] = $flowData;
        }

        $session = $this->stripe->billingPortal->sessions->create($params);

        return $session->url;
    }

    /**
     * Performs a background, real-time lazy synchronization of the Tenant's subscription status.
     * This acts as an automated "self-healing" fallback if webhooks are delayed or dropped.
     */
    public function syncSubscriptionStatus(Tenant $tenant): void
    {
        if (!$tenant->stripeCustomerId) {
            return;
        }

        // --- Self-Healing Fallback for Local Cardless Trials ---
        if (!$tenant->stripeCustomerId) {
            if ($tenant->subscriptionPlan === 'trial') {
                $currentPeriodEnd = $tenant->currentPeriodEnd;
                // If the local card-free 14-day trial has passed, suspend workspace access
                if ($currentPeriodEnd && $currentPeriodEnd < new \DateTimeImmutable()) {
                    if ($tenant->status !== 'suspended') {
                        $tenant->status = 'suspended';
                        $this->tenantRepository->save($tenant, true);
                    }
                }
            }
            return;
        }

        try {
            // Check if we have an explicit subscription ID to verify
            if ($tenant->stripeSubscriptionId) {
                $subscription = $this->stripe->subscriptions->retrieve($tenant->stripeSubscriptionId);
                $stripeStatus = $subscription->status;
                $planId = $subscription->items->data[0]->price->id ?? null;
            } else {
                // If there's no stored subscription ID, perform a lazy recovery check on their customer account
                $subscriptions = $this->stripe->subscriptions->all([
                    'customer' => $tenant->stripeCustomerId,
                    'limit' => 1
                ]);

                if (count($subscriptions->data) > 0) {
                    $subscription = $subscriptions->data[0];
                    $stripeStatus = $subscription->status;
                    $planId = $subscription->items->data[0]->price->id ?? null;

                    // Auto-heal: Bind the missing subscription details locally
                    $tenant->stripeSubscriptionId = $subscription->id;
                } else {
                    $stripeStatus = 'none';
                    $planId = null;
                }
            }

            if (isset($subscription) && $subscription) {
                $cancelAtPeriodEnd = $subscription->cancel_at_period_end ?? false;
                $currentPeriodEnd = $subscription->current_period_end
                    ? new \DateTimeImmutable()->setTimestamp($subscription->current_period_end)
                    : null;

                $tenant->cancelAtPeriodEnd = $cancelAtPeriodEnd;
                $tenant->currentPeriodEnd = $currentPeriodEnd;
            } else {
                $tenant->cancelAtPeriodEnd = false;
                $tenant->currentPeriodEnd = null;
            }

            // Map Stripe status (active, trialing, past_due, canceled, unpaid) to Tenant status
            $localStatus = $tenant->status;
            $newStatus = 'suspended';

            if (in_array($stripeStatus, ['active', 'trialing'])) {
                $newStatus = 'active';
            } elseif ($stripeStatus === 'past_due') {
                $newStatus = 'past_due';
            }

            // Update plan context if mapped
            if ($planId) {
                $tenant->subscriptionPlan = $planId;
            }

            // Only issue a database flush if properties have actually changed to conserve resources
            if ($localStatus !== $newStatus) {
                $tenant->status = $newStatus;
                $this->tenantRepository->save($tenant, true);
            }

        } catch (\Exception $e) {
            // Log error internally. Fail silently so the user dashboard still loads safely
            // If the subscription was explicitly deleted in Stripe, update the database status
            if (str_contains($e->getMessage(), 'No such subscription')) {
                $tenant->status = 'suspended';
                $tenant->stripeSubscriptionId = null;
                $this->tenantRepository->save($tenant, true);
            }
        }
    }
}
