<?php

namespace App\Doctrine;

use App\Entity\Tenant;
use App\Service\Standardgruppen;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Gibt jedem neuen Mandanten die vier Vorlagen mit.
 *
 * Dasselbe Muster wie TenantPipelineListener: prePersist ohne eigenen
 * flush() — ein flush() aus einem Listener heraus laeuft mitten im Commit
 * des laufenden UnitOfWork (Analyse.md C28).
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
