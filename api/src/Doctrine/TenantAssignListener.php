<?php

namespace App\Doctrine;

use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Automatically sets the logged-in user's tenant when a tenant-owned
 * record is created. Clients can neither forget nor forge the tenant this
 * way — the field never comes from the request.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantAssignListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TenantOwnedInterface || $entity->getTenant() !== null) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->getTenant() !== null) {
            $entity->setTenant($user->getTenant());
        }
    }
}
