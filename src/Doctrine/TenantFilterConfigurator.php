<?php

namespace App\Doctrine;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class TenantFilterConfigurator
 *
 * Listens to Kernel Requests to detect the logged-in user and safely populate the
 * active Tenant ID context into our dynamic Doctrine SQL Filter.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest')]
readonly class TenantFilterConfigurator
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security               $security,
        private UrlGeneratorInterface  $router
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $tenant = $user->tenant;
        if (!$tenant) {
            return;
        }

        // --- BILLING BLOCKING ENFORCEMENT ---
        // If the tenant is suspended (unpaid/canceled/manually revoked)
        if ($tenant->status === 'suspended') {
            // Exclude the Billing dashboard route from the blockade
            // so the Admin can still access the Stripe customer portal to update their card!
            $currentRoute = $event->getRequest()->attributes->get('_route');
            if (!in_array($currentRoute, ['internal_billing_dashboard', 'internal_billing_portal', 'internal_billing_subscribe', 'security_logout'])) {

                // Redirect them gracefully to the Billing subscription warning screen
                $response = new RedirectResponse(
                    $this->router->generate('internal_billing_dashboard')
                );
                $event->setResponse($response);
                return;
            }
        }

        // 3. Enable the custom SQL filter and set the parameter
        // We use the Doctrine type 'uuid' to automatically handle conversion (binary vs string formats)
        $filter = $this->em->getFilters()->enable('tenant_filter');
        $filter->setParameter('tenant_id', $tenant->id, 'uuid');
    }
}
