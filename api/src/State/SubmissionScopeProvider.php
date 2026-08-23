<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\FormSubmission;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kollektions-Scoping fuer FormSubmission (Verbesserungs-Durchlauf Punkt 1,
 * Muster HolidayRequestScopeProvider aus Modul #9): ohne submissions.view/
 * submissions.manage/ROLE_ADMIN sieht ein Nutzer in GET /form_submissions
 * NUR seine eigenen Eintraege -- WHERE createdBy=:me wird VOR den Standard-
 * Extensions angehaengt (echte Container-Decoration von collection_provider
 * kaeme zu spaet fuers Pagination-Count, siehe AppointmentRangeProvider-
 * Kommentar in Modul #8).
 *
 * @implements ProviderInterface<FormSubmission>
 */
final class SubmissionScopeProvider implements ProviderInterface
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
        $entityClass = $operation->getClass() ?? FormSubmission::class;
        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        $queryBuilder = $manager->getRepository($entityClass)->createQueryBuilder('o');
        $queryNameGenerator = new QueryNameGenerator();

        $user = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');
        $canSeeAll = $isAdmin || ($user instanceof User && (
            in_array('submissions.view', $user->getPermissions(), true)
            || in_array('submissions.manage', $user->getPermissions(), true)
        ));

        if (!$canSeeAll && $user instanceof User) {
            $queryBuilder->andWhere('o.createdBy = :scope_me')
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
