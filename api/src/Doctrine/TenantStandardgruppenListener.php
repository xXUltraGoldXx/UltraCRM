<?php

namespace App\Doctrine;

use App\Entity\Tenant;
use App\Service\Standardgruppen;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Gives every new tenant the four default groups.
 *
 * Same pattern as TenantPipelineListener: prePersist without its own
 * flush() — a flush() from inside a listener would run in the middle of
 * the ongoing UnitOfWork commit.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantStandardgruppenListener
{
    public function __construct(private readonly Standardgruppen $standardgruppen)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $tenant = $args->getObject();
        if ($tenant instanceof Tenant) {
            $this->standardgruppen->anlegen($tenant);
        }
    }
}
