<?php

namespace App\Controller\TenantAdmin;

use App\Entity\Tenant;
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
    public function __construct(
        private readonly StripeBillingService $billingService,
        #[Autowire(param: 'env(STRIPE_PRICE_ID)')] private readonly string $stripePriceId
    ) {}

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $user->tenant;

        if ($tenant) {
            // Execute the on-the-fly reconciliation check with Stripe
            $this->billingService->syncSubscriptionStatus($tenant);
        }

        return $this->render('internal/billing/dashboard.html.twig', [
            'tenant' => $tenant,
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

        try {
            $checkoutUrl = $this->billingService->createCheckoutSession($user, $this->stripePriceId);
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
