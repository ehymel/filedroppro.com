<?php

namespace App\Doctrine;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Class TenantFilterConfigurator
 *
 * Listens to Kernel Requests to detect the logged-in user and safely populate the
 * active Tenant ID context into our dynamic Doctrine SQL Filter.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest')]
class TenantFilterConfigurator
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        // 1. Retrieve the authenticated User
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // 2. Fetch the associated Tenant
        $tenant = $user->tenant;
        if (!$tenant) {
            return;
        }

        // 3. Enable the custom SQL filter and set the parameter
        // We use the Doctrine type 'uuid' to automatically handle conversion (binary vs string formats)
        $filter = $this->em->getFilters()->enable('tenant_filter');
        $filter->setParameter('tenant_id', $tenant->id, 'uuid');
    }
}
