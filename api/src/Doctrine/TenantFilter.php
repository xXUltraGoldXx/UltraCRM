<?php

namespace App\Doctrine;

use App\Entity\TenantOwnedInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Haengt an jede Abfrage mandantengebundener Entities die Bedingung
 * tenant_id = :tenant_id an. Aktiviert wird der Filter je Request im
 * TenantFilterSubscriber; fuer Superadmins bleibt er aus.
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantOwnedInterface::class)) {
            return '';
        }

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $this->getParameter('tenant_id'));
    }
}
