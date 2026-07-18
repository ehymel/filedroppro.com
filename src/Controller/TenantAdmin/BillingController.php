<?php

namespace App\Controller\TenantAdmin;

use App\Entity\User;
use App\Service\StripeBillingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/internal/billing', name: 'internal_billing_')]
#[IsGranted('ROLE_ADMIN')]
class BillingController extends AbstractController
{
    private array $stripePlanPrices = [];

    public function __construct(
        private readonly StripeBillingService $billingService,
        #[Autowire(param: 'env(STRIPE_PRICE_BASIC)')] string $stripePlanBasic,
        #[Autowire(param: 'env(STRIPE_PRICE_PRO)')] string $stripePlanPro,
        #[Autowire(param: 'env(STRIPE_PRICE_ENTERPRISE)')] string $stripePlanEnterprise,
    ) {
        $this->stripePlanPrices = [
            'basic' => $stripePlanBasic,
            'pro' => $stripePlanPro,
            'enterprise' => $stripePlanEnterprise,
        ];
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $user->tenant;

        $activePlanName = 'None';

        if ($tenant) {
            // Execute the on-the-fly reconciliation check with Stripe
            $this->billingService->syncSubscriptionStatus($tenant);

            // Reverse map the Stripe Price ID back to a human-readable plan alias
            $priceToPlanMap = array_flip($this->stripePlanPrices);
            $rawPlanId = $tenant->subscriptionPlan;

            if ($rawPlanId && isset($priceToPlanMap[$rawPlanId])) {
                $activePlanName = $priceToPlanMap[$rawPlanId];
            } elseif ($rawPlanId) {
                $activePlanName = 'Custom/Legacy Plan';
            }
        }

        return $this->render('internal/billing/dashboard.html.twig', [
            'tenant' => $tenant,
            'activePlanName' => $activePlanName,
        ]);
    }

    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    public function subscribe(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('billing_subscribe', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_billing_dashboard');
        }

        $plan = $request->request->get('plan', 'basic');
        $allowedPlans = ['trial', 'basic', 'pro', 'enterprise'];

        if (!in_array($plan, $allowedPlans)) {
            $this->addFlash('danger', 'Invalid plan configuration selected.');
            return $this->redirectToRoute('internal_billing_dashboard');
        }

        try {
            $trialDays = null;
            if ($plan === 'trial') {
                // Trial starts on the Pro plan with a 14-day free trial period
                $priceId = $this->stripePlanPrices['pro'];
                $trialDays = 14;
            } else {
                $priceId = $this->stripePlanPrices[$plan] ?? null;
            }

            if (!$priceId || str_contains($priceId, 'placeholder')) {
                $this->addFlash('danger', sprintf('The selected plan "%s" is not configured in this server\'s services.yaml configuration.', $plan));
                return $this->redirectToRoute('internal_billing_dashboard');
            }

            $checkoutUrl = $this->billingService->createCheckoutSession($user, $priceId, $trialDays);
            return new RedirectResponse($checkoutUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Failed to initialize payment gateway: ' . $e->getMessage());
            return $this->redirectToRoute('internal_billing_dashboard');
        }
    }

    #[Route('/portal', name: 'portal', methods: ['POST'])]
    public function openPortal(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $user->tenant;

        if (!$this->isCsrfTokenValid('billing_portal', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_billing_dashboard');
        }

        try {
            $portalUrl = $this->billingService->createPortalSession($tenant);
            return new RedirectResponse($portalUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Failed to load self-service portal: ' . $e->getMessage());
            return $this->redirectToRoute('internal_billing_dashboard');
        }
    }
}
