<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Beschraenkt Benutzer-Abfragen der API auf den eigenen Mandanten.
 *
 * `User` implementiert bewusst NICHT TenantOwnedInterface: der
 * Doctrine-Filter haengt am angemeldeten Benutzer, bei der Anmeldung gibt es
 * aber noch keinen. Waere User gefiltert, faende der Login den Benutzer nicht
 * mehr — die Anmeldung wuerde sich selbst aussperren.
 *
 * Dadurch waren Benutzer bislang mandantenuebergreifend sichtbar und
 * aenderbar: ein Administrator aus Mandant B konnte Benutzer aus Mandant A
 * lesen und bearbeiten, und `/users/picker` gab jedem Angemeldeten die Namen
 * aller Benutzer aller Mandanten (Analyse.md C37).
 *
 * Diese Erweiterung greift nur in den Abfragen von API Platform. Der
 * Anmeldeweg laeuft nicht darueber und bleibt unberuehrt.
 */
final class UserTenantExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->einschraenken($queryBuilder, $resourceClass);
    }

    /** @param array<string, mixed> $identifiers */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->einschraenken($queryBuilder, $resourceClass);
    }

    private function einschraenken(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== User::class) {
            return;
        }

        $angemeldet = $this->security->getUser();
        if (!$angemeldet instanceof User) {
            return;
        }

        // Der Superadmin verwaltet die Mandanten und muss alle Benutzer sehen.
        if (in_array('ROLE_SUPERADMIN', $angemeldet->getRoles(), true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        // Ohne eigenen Mandanten wird nichts sichtbar — Richtung "zu",
        // wie beim Mandantenfilter der uebrigen Entities.
        if ($angemeldet->getTenant() === null) {
            $queryBuilder->andWhere(sprintf('%s.id = :nur_ich', $alias))
                ->setParameter('nur_ich', $angemeldet->getId());

            return;
        }

        $queryBuilder->andWhere(sprintf('%s.tenant = :eigener_mandant', $alias))
            ->setParameter('eigener_mandant', $angemeldet->getTenant());
    }
}
