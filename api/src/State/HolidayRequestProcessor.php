<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\HolidayRequest;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Zustandsmaschine fuer Urlaubsantraege (Modul #9). Analog SubmissionProcessor/
 * AppointmentProcessor (Decorator um den Standard-Persist-Processor), aber mit
 * echter Uebergangspruefung statt nur einem Autoset-Feld -- ein Patch traegt
 * hier bewusst den GEWUENSCHTEN Zielstatus, der Processor entscheidet, ob der
 * Uebergang erlaubt ist.
 *
 * @implements ProcessorInterface<HolidayRequest, HolidayRequest|void>
 */
final class HolidayRequestProcessor implements ProcessorInterface
{
    /** Erlaubte Zielstaende je Ausgangsstatus. rejected/withdrawn fehlen bewusst -- Endzustaende. */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'withdrawn'],
        'approved' => ['withdrawn'],
    ];

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private Connection $connection,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof HolidayRequest) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $user = $this->security->getUser();

        if ($data->getId() === null) {
            $this->prepareNew($data, $user);

            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        // Opus-Review Befund 2: die Transaktion MUSS das FOR-UPDATE-Lesen UND
        // das eigentliche Schreiben (persistProcessor->process() -> $em->flush())
        // umspannen -- ein commit() direkt nach dem Lesen wuerde den Zeilenlock
        // wieder freigeben, BEVOR das UPDATE laeuft, und die Race waere nicht
        // wirklich verhindert. Doctrine's EntityManager nutzt dieselbe DBAL-
        // Connection, flush() nimmt an einer bereits offenen Transaktion teil
        // (Nesting-Zaehler), committet also nicht selbst vorzeitig.
        $this->connection->beginTransaction();
        try {
            $this->applyTransitionIfAny($data, $user);
            $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /** Neuanlage: requestedBy/requestedDays/status serverseitig, nie vom Client (siehe Entity-Kommentar). */
    private function prepareNew(HolidayRequest $data, mixed $user): void
    {
        if ($user instanceof User) {
            $data->setRequestedBy($user);
        }
        if ($data->getStartsAt() === null || $data->getEndsAt() === null) {
            throw new BadRequestHttpException('Start- und Enddatum sind erforderlich.');
        }
        if ($data->getEndsAt() < $data->getStartsAt()) {
            throw new BadRequestHttpException('Enddatum darf nicht vor dem Startdatum liegen.');
        }
        $days = $this->calculateWeekdays($data->getStartsAt(), $data->getEndsAt());
        if ($days <= 0.0) {
            throw new BadRequestHttpException('Der Zeitraum enthält keine Werktage (Mo–Fr).');
        }
        $data->setRequestedDays($days);
        $data->setStatus('pending');
        $data->setRejectedReason(null);
        $data->setDecidedBy(null);
        $data->setDecidedAt(null);
    }

    /**
     * Patch: alten Stand (Status UND Zeitraum) per Direkt-SQL lesen (die Entity
     * ist im Identity-Map bereits mit den NEUEN, vom Client gewuenschten Werten
     * ueberschrieben -- eine frische SQL-Abfrage umgeht das und trifft die echte
     * DB-Zeile). Opus-Review Befund 2: SELECT ... FOR UPDATE -- laeuft innerhalb
     * der vom Aufrufer (process()) offenen Transaktion, die auch das eigentliche
     * Schreiben mit umspannt; eine zweite parallele Genehmigung wartet auf die
     * erste und sieht danach garantiert den neuen Status/Zeitraum statt einer
     * veralteten Momentaufnahme.
     */
    private function applyTransitionIfAny(HolidayRequest $data, mixed $user): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, starts_at, ends_at FROM holiday_request WHERE id = ? FOR UPDATE',
            [$data->getId()]
        );
        if ($row === false) {
            throw new NotFoundHttpException('Urlaubsantrag nicht gefunden.');
        }
        $previousStatus = $row['status'];
        $previousStartsAt = new \DateTimeImmutable($row['starts_at']);
        $previousEndsAt = new \DateTimeImmutable($row['ends_at']);

        $newStatus = $data->getStatus();
        $dateChanged = $data->getStartsAt()?->format('Y-m-d') !== $previousStartsAt->format('Y-m-d')
            || $data->getEndsAt()?->format('Y-m-d') !== $previousEndsAt->format('Y-m-d');

        // Opus-Review Befund 1: startsAt/endsAt stehen in holiday:write und sind
        // damit unabhaengig vom Status patchbar -- ohne diese Pruefung koennte
        // der Eigentuemer einen BEREITS ENTSCHIEDENEN Antrag (v.a. approved)
        // einfach verschieben: keine Ueberlappungspruefung laeuft erneut, und
        // requestedDays (Kontingent) bliebe der ALTE Wert stehen bei neuem
        // Zeitraum -- Kontingent waere manipulierbar.
        if ($dateChanged) {
            if ($previousStatus !== 'pending') {
                throw new BadRequestHttpException('Zeitraum eines entschiedenen Antrags ist nicht mehr änderbar.');
            }
            if ($data->getStartsAt() === null || $data->getEndsAt() === null) {
                throw new BadRequestHttpException('Start- und Enddatum sind erforderlich.');
            }
            if ($data->getEndsAt() < $data->getStartsAt()) {
                throw new BadRequestHttpException('Enddatum darf nicht vor dem Startdatum liegen.');
            }
            $days = $this->calculateWeekdays($data->getStartsAt(), $data->getEndsAt());
            if ($days <= 0.0) {
                throw new BadRequestHttpException('Der Zeitraum enthält keine Werktage (Mo–Fr).');
            }
            $data->setRequestedDays($days);
        }

        if ($newStatus === $previousStatus) {
            // Kein Statuswechsel -- z.B. nur reason/representative/Zeitraum
            // (pending, oben bereits geprueft) bearbeitet. Wer ueberhaupt
            // patchen darf, regelt bereits die Item-Security (ROLE_ADMIN/
            // holiday.manage/Eigentuemer), keine weitere Pruefung noetig.
            return;
        }

        $isAdmin = $this->security->isGranted('ROLE_ADMIN');
        $canManage = $isAdmin || ($user instanceof User && in_array('holiday.manage', $user->getPermissions(), true));
        $isOwner = $user instanceof User && $data->getRequestedBy()?->getId() === $user->getId();

        $this->assertTransitionAllowed((string) $previousStatus, $newStatus, $isOwner, $canManage, $isAdmin);

        if ($newStatus === 'approved') {
            $this->assertNoOverlap($data);
        }
        if ($newStatus === 'rejected' && trim((string) $data->getRejectedReason()) === '') {
            throw new UnprocessableEntityHttpException('Ablehnungsgrund ist erforderlich.');
        }
        if ($newStatus !== 'rejected') {
            // Ablehnungsgrund gehoert nur zu einer Ablehnung -- bei approved/withdrawn
            // nicht stehen lassen, falls versehentlich mitgeschickt.
            $data->setRejectedReason(null);
        }

        if ($user instanceof User) {
            $data->setDecidedBy($user);
        }
        $data->setDecidedAt(new \DateTimeImmutable());
    }

    private function assertTransitionAllowed(string $from, string $to, bool $isOwner, bool $canManage, bool $isAdmin): void
    {
        $allowedTargets = self::ALLOWED_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowedTargets, true)) {
            throw new BadRequestHttpException(sprintf('Übergang von "%s" zu "%s" ist nicht erlaubt.', $from, $to));
        }

        if ($to === 'approved') {
            if (!$canManage) {
                throw new AccessDeniedHttpException('Nur mit Genehmigungsrecht (holiday.manage) freigebbar.');
            }
            if ($isOwner && !$isAdmin) {
                throw new AccessDeniedHttpException('Eigene Anträge können nicht selbst genehmigt werden.');
            }
        } elseif ($to === 'rejected') {
            if (!$canManage) {
                throw new AccessDeniedHttpException('Nur mit Genehmigungsrecht (holiday.manage) ablehnbar.');
            }
        } elseif ($to === 'withdrawn') {
            if ($from === 'pending') {
                if (!$isOwner && !$canManage) {
                    throw new AccessDeniedHttpException('Nur der Antragsteller oder eine Person mit Genehmigungsrecht kann zurückziehen.');
                }
            } else { // from === 'approved'
                if (!$canManage) {
                    throw new AccessDeniedHttpException('Ein genehmigter Antrag kann nur von einer Person mit Genehmigungsrecht zurückgezogen werden.');
                }
            }
        }
    }

    /** Nur bei Genehmigung: Ueberschneidung mit bereits genehmigten Antraegen DERSELBEN Person. */
    private function assertNoOverlap(HolidayRequest $data): void
    {
        $requesterId = $data->getRequestedBy()?->getId();
        if ($requesterId === null || $data->getStartsAt() === null || $data->getEndsAt() === null) {
            return;
        }
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM holiday_request
             WHERE requested_by_id = ? AND status = ? AND id != ?
               AND starts_at < ? AND ends_at > ?',
            [
                $requesterId,
                'approved',
                $data->getId(),
                $data->getEndsAt()->format('Y-m-d'),
                $data->getStartsAt()->format('Y-m-d'),
            ]
        );
        if ($count > 0) {
            throw new ConflictHttpException('Der Zeitraum überschneidet sich mit einem bereits genehmigten Urlaub.');
        }
    }

    /** Werktage Mo-Fr im Zeitraum, beide Enden inklusiv. */
    private function calculateWeekdays(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        $days = 0;
        $cursor = $start;
        while ($cursor <= $end) {
            if ((int) $cursor->format('N') <= 5) {
                $days++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return (float) $days;
    }
}
