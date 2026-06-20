<?php

namespace App\Doctrine;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Class TenantEntityListener
 *
 * Automatically intercepts Doctrine lifecycle events to populate the 'tenant' association on
 * newly persisted entities (like Client) before they hit the database, saving you from having to
 * manually write `$entity->setTenant($currentTenant)` in every single Controller and Form.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 500, connection: 'default')]
class TenantEntityListener
{
    public function __construct(private Security $security) {}

    /**
     * Listens to the PrePersist event for entities before database insertion.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!property_exists($entity, 'tenant')) {
            return;
        }

        if ($entity->tenant !== null) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->tenant instanceof Tenant) {
            $entity->setTenant($user->tenant);
        }
    }
}
