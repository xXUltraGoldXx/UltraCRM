<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Appointment;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Zeitraum-Provider fuer Appointment::class-Kollektionen (Modul #8, Schritt
 * "Zeitraum"): liest ?from=&to= und haengt eine Ueberlappungsbedingung
 * (startsAt < :to AND endsAt > :from) an, BEVOR Filter/Sortierung/Pagination
 * laufen -- nur so bleibt COUNT/LIMIT/OFFSET der Paginierung korrekt (eine
 * echte Container-Decoration von api_platform.doctrine.orm.state.
 * collection_provider kann die WHERE-Klausel nicht mehr in die intern schon
 * gebaute Query einschleusen, siehe Kurzbericht -- deshalb Re-Implementation
 * derselben drei Standard-Extensions: filter/order/pagination, in exakt der
 * dort verwendeten Prioritaets-Reihenfolge).
 *
 * @implements ProviderInterface<Appointment>
 */
final class AppointmentRangeProvider implements ProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.filter')]
        private readonly QueryCollectionExtensionInterface $filterExtension,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.order')]
        private readonly QueryCollectionExtensionInterface $orderExtension,
        #[Autowire(service: 'api_platform.doctrine.orm.query_extension.pagination')]
        private readonly QueryResultCollectionExtensionInterface $paginationExtension,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Client-Input darf nie eine 500 erzeugen: ungueltiges Datum -> 400
     * (Opus-Review 22.08. — new \DateTimeImmutable('abc') wirft sonst
     * ungefangen bis in den Error-Handler durch).
     */
    private function parseDate(string $value, string $param): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('Ungueltiger Wert fuer "%s".', $param));
        }
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $entityClass = $operation->getClass() ?? Appointment::class;
        $manager = $this->managerRegistry->getManagerForClass($entityClass);
        $queryBuilder = $manager->getRepository($entityClass)->createQueryBuilder('o');
        $queryNameGenerator = new QueryNameGenerator();

        $request = $this->requestStack->getCurrentRequest();
        $from = $request?->query->get('from');
        $to = $request?->query->get('to');

        // Ueberlappung: ein Termin gehoert ins Fenster, wenn er nicht komplett
        // davor endet und nicht komplett danach beginnt.
        if ($to) {
            $queryBuilder->andWhere('o.startsAt < :range_to')
                ->setParameter('range_to', $this->parseDate($to, 'to'));
        }
        if ($from) {
            $queryBuilder->andWhere('o.endsAt > :range_from')
                ->setParameter('range_from', $this->parseDate($from, 'from'));
        }

        // Dieselbe Reihenfolge wie der Standard-CollectionProvider (Prioritaeten
        // filter -16 > order -32 > pagination -64, siehe vendor/api-platform/
        // symfony/Bundle/Resources/config/doctrine_orm.php) -- SearchFilter
        // (assignedTo/customer) und OrderFilter (startsAt) aus der Appointment-
        // Entity greifen dadurch unveraendert weiter.
        $this->filterExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);
        $this->orderExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);
        $this->paginationExtension->applyToCollection($queryBuilder, $queryNameGenerator, $entityClass, $operation, $context);

        if ($this->paginationExtension->supportsResult($entityClass, $operation, $context)) {
            return $this->paginationExtension->getResult($queryBuilder, $entityClass, $operation, $context);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
