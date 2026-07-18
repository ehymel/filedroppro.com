<?php

namespace App\Controller\Webhook;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        #[Autowire(param: 'env(STRIPE_WEBHOOK_SECRET)')] private readonly string $stripeWebhookSecret
    ) {}

    #[Route('/webhook/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');

        if (!$sigHeader) {
            return new JsonResponse(['error' => 'Missing signature header'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Verify signature using Stripe SDK
            $event = Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid signature: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Handle relevant subscription events
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $tenantId = $session->metadata->tenant_id ?? null;  // set by my StripeBillingService

                if ($tenantId) {
                    /** @var Tenant $tenant */
                    $tenant = $this->tenantRepository->find($tenantId);
                    if ($tenant) {
                        $tenant->stripeCustomerId = $session->customer;
                        $tenant->stripeSubscriptionId = $session->subscription;
                        $tenant->status = 'active'; // Activate the tenant profile
                        $this->tenantRepository->save($tenant, true);
                    }
                }
                break;

            case 'customer.subscription.updated':
                /** @var Subscription $subscription */
                $subscription = $event->data->object;
                /** @var Tenant $tenant */
                $tenant = $this->tenantRepository->findOneBy(['stripeCustomerId' => $subscription->customer]);

                if ($tenant) {
                    // Stripe status mapping (active, trialing, past_due, canceled, unpaid)
                    // Per Stripe docs, possible values are incomplete, incomplete_expired, trialing, active, past_due, canceled, unpaid, or paused
                    $stripeStatus = $subscription->status;

                    if ($stripeStatus === 'active' || $stripeStatus === 'trialing') {
                        $tenant->status = 'active';
                    } elseif ($stripeStatus === 'past_due') {
                        $tenant->status = 'past_due'; // Send notices in dashboard but keep active
                    } else {
                        $tenant->status = 'suspended'; // Lock organization completely
                    }
                    $this->tenantRepository->save($tenant, true);
                }
                break;

            case 'customer.subscription.deleted':
                /** @var Subscription $subscription */
                $subscription = $event->data->object;
                /** @var Tenant $tenant */
                $tenant = $this->tenantRepository->findOneBy(['stripeCustomerId' => $subscription->customer]);

                if ($tenant) {
                    $tenant->status = 'suspended'; // Suspend workspace upon cancellation
                    $tenant->stripeSubscriptionId = null;
                    $this->tenantRepository->save($tenant, true);
                }
                break;
        }

        return new JsonResponse(['status' => 'success']);
    }
}
