<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Appointment;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dashboard-Karte "Anstehende Erinnerungen" (Modul #8, Schritt "Erinnerungen"):
 * faellige, aber noch nicht verstrichene Erinnerungen -- reminderAt liegt in
 * der Vergangenheit/Gegenwart, der eigentliche Termin (startsAt) aber noch in
 * der Zukunft (Anwendungsfall "Wartung faellig in 6 Monaten": Erinnerung soll
 * VOR dem Termin selbst aufploppen). Kein Mailer im Projekt -- ausschliesslich
 * per Dashboard-Abfrage ausgewertet, kein Dismiss-Mechanismus in v1.
 *
 * Eigene, feste Sortierung (reminderAt ASC) statt der generischen Range-Logik
 * aus AppointmentRangeProvider -- hier gibt es keine Paginierung/Filter zu
 * beachten, die Liste ist naturgemaess klein.
 *
 * @implements ProviderInterface<Appointment>
 */
final class AppointmentReminderProvider implements ProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $manager = $this->managerRegistry->getManagerForClass(Appointment::class);
        $now = new \DateTimeImmutable();

        return $manager->getRepository(Appointment::class)->createQueryBuilder('o')
            ->andWhere('o.reminderAt IS NOT NULL')
            ->andWhere('o.reminderAt <= :now')
            ->andWhere('o.startsAt > :now')
            ->orderBy('o.reminderAt', 'ASC')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
