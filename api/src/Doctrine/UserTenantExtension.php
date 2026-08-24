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
 * Restricts the API's user queries to the caller's own tenant.
 *
 * `User` deliberately does NOT implement TenantOwnedInterface: the
 * Doctrine filter depends on the logged-in user, but at login time there
 * is none yet. If User were filtered, login would no longer find the
 * user — authentication would lock itself out.
 *
 * As a consequence, users used to be visible and editable across
 * tenants: an administrator in tenant B could read and edit users from
 * tenant A, and `/users/picker` returned the names of every user of
 * every tenant to any logged-in caller.
 *
 * This extension only applies to API Platform queries. The login path
 * does not go through it and is unaffected.
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

        // The superadmin manages the tenants and must see all users.
        if (in_array('ROLE_SUPERADMIN', $angemeldet->getRoles(), true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        // Without a tenant of their own, nothing is visible — fail
        // closed, same as the tenant filter on the other entities.
        if ($angemeldet->getTenant() === null) {
            $queryBuilder->andWhere(sprintf('%s.id = :nur_ich', $alias))
                ->setParameter('nur_ich', $angemeldet->getId());

            return;
        }

        $queryBuilder->andWhere(sprintf('%s.tenant = :eigener_mandant', $alias))
            ->setParameter('eigener_mandant', $angemeldet->getTenant());
    }
}
