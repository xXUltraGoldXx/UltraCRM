<?php

namespace App\Doctrine;

use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Setzt beim Anlegen mandantengebundener Datensaetze automatisch den
 * Mandanten des eingeloggten Users. Clients koennen den Mandanten damit
 * weder vergessen noch faelschen — das Feld kommt nie aus dem Request.
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
