<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\HolidayRequest;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Kalender-Integration (Modul #9 x #8): /holiday_requests/calendar?from=&to= --
 * NUR genehmigte Antraege, unpaginiert, fuer alle Authentifizierten (die
 * eigentliche Sichtbarkeits-Einschraenkung passiert schon in
 * HolidayRequestScopeProvider fuer die normale Collection; der Kalender ist
 * bewusst ein Gemeinschafts-Kalender -- jeder darf sehen, WER Urlaub hat,
 * nicht aber die Details/Ablehnungsgruende, siehe holiday:calendar-Gruppe
 * am Entity ohne reason/rejectedReason).
 *
 * Kein Filter/Order/Pagination-Nachbau noetig (anders als AppointmentRange-
 * Provider/HolidayRequestScopeProvider) -- paginationEnabled ist false und
 * die Sortierung ist fest (startsAt), es gibt hier keine SearchFilter/
 * OrderFilter-Konfiguration zu respektieren.
 *
 * @implements ProviderInterface<HolidayRequest>
 */
final class HolidayRequestCalendarProvider implements ProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** Client-Input darf nie eine 500 erzeugen -- Muster aus AppointmentRangeProvider (Modul #8). */
    private function parseDate(string $value, string $param): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('Ungültiger Wert für "%s".', $param));
        }
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $manager = $this->managerRegistry->getManagerForClass(HolidayRequest::class);
        $queryBuilder = $manager->getRepository(HolidayRequest::class)->createQueryBuilder('o')
            ->andWhere('o.status = :status')
            ->setParameter('status', 'approved')
            ->orderBy('o.startsAt', 'ASC');

        $request = $this->requestStack->getCurrentRequest();
        $from = $request?->query->get('from');
        $to = $request?->query->get('to');

        if ($to) {
            $queryBuilder->andWhere('o.startsAt < :range_to')
                ->setParameter('range_to', $this->parseDate($to, 'to'));
        }
        if ($from) {
            $queryBuilder->andWhere('o.endsAt > :range_from')
                ->setParameter('range_from', $this->parseDate($from, 'from'));
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
