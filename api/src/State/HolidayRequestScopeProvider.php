<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\HolidayRequest;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kollektions-Scoping fuer HolidayRequest (Modul #9, "ERSTMALS echte
 * permissions[]-Pruefung"): ohne holiday.manage/ROLE_ADMIN sieht ein Nutzer
 * in GET /holiday_requests NUR seine eigenen Antraege -- WHERE requestedBy=:me
 * wird VOR den Standard-Extensions angehaengt (Muster AppointmentRangeProvider,
 * Modul #8: eine echte Container-Decoration von collection_provider kaeme zu
 * spaet fuers Pagination-Count, deshalb Re-Implementation derselben drei
 * Extensions filter/order/pagination in identischer Prioritaets-Reihenfolge).
 *
 * @implements ProviderInterface<HolidayRequest>
 */
final class HolidayRequestScopeProvider implements ProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly Security $security,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.filter')]
        private readonly QueryCollectionExtensionInterface $filterExtension,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.order')]
        private readonly QueryCollectionExtensionInterface $orderExtension,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.pagination')]
        private readonly QueryResultCollectionExtensionInterface $paginationExtension,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $entityClass = $operation->getClass() ?? HolidayRequest::class;
        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        $queryBuilder = $manager->getRepository($entityClass)->createQueryBuilder('o');
        $queryNameGenerator = new QueryNameGenerator();

        $user = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');
        $canManage = $isAdmin || ($user instanceof User && in_array('holiday.manage', $user->getPermissions(), true));

        if (!$canManage && $user instanceof User) {
            $queryBuilder->andWhere('o.requestedBy = :scope_me')
                ->setParameter('scope_me', $user);
        }

        // Dieselbe Reihenfolge wie der Standard-CollectionProvider (siehe
        // AppointmentRangeProvider-Kommentar, Modul #8).
        $this->filterExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);
        $this->orderExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);
        $this->paginationExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);

        if ($this->paginationExtension->supportsResult($entityClass, $operation, $context)) {
            return $this->paginationExtension->getResult($queryBuilder, $entityClass, $operation, $context);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
