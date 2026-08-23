<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\HolidayRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Urlaubsantrag (Modul #9). startsAt/endsAt als date_immutable -- Urlaub hat
 * keine Uhrzeit (anders als Appointment::startsAt/endsAt, die DateTimeImmutable
 * sind). requestedBy/requestedDays/status/decidedBy/decidedAt/rejectedReason
 * stehen bewusst NICHT in der write-Gruppe -- die Zustandsmaschine
 * (HolidayRequestProcessor) ist der einzige Ort, der sie setzt (Muster wie
 * Appointment::createdBy).
 */
#[ORM\Entity(repositoryClass: HolidayRequestRepository::class)]
#[ORM\Index(columns: ['requested_by_id', 'status'], name: 'idx_holiday_requester_status')]
#[ORM\Index(columns: ['starts_at', 'ends_at'], name: 'idx_holiday_range')]
#[ApiResource(
    operations: [
        new GetCollection(provider: \App\State\HolidayRequestScopeProvider::class),
        // Kalender-Integration (#8+#9): nur genehmigte Antraege, Minimalfelder
        // (siehe holiday:calendar-Gruppe -- KEIN reason/rejectedReason), fuer
        // alle Authentifizierten. MUSS vor Get(/holiday_requests/{id}) stehen --
        // {id} hat kein \d+-Requirement, sonst faengt die Item-Route "calendar"
        // faelschlich als id ab (dieselbe Falle wie /users/picker und
        // /appointments/reminders_due in Modul #8).
        new GetCollection(
            uriTemplate: '/holiday_requests/calendar',
            paginationEnabled: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['groups' => ['holiday:calendar']],
            provider: \App\State\HolidayRequestCalendarProvider::class,
        ),
        new Get(security: "is_granted('ROLE_ADMIN') or 'holiday.manage' in user.getPermissions() or (object.getRequestedBy() and object.getRequestedBy().getId() == user.getId())"),
        new Post(processor: \App\State\HolidayRequestProcessor::class),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or 'holiday.manage' in user.getPermissions() or (object.getRequestedBy() and object.getRequestedBy().getId() == user.getId())",
            processor: \App\State\HolidayRequestProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_ADMIN') or 'holiday.manage' in user.getPermissions() or (object.getRequestedBy() and object.getRequestedBy().getId() == user.getId())"),
    ],
    normalizationContext: ['groups' => ['holiday:read']],
    denormalizationContext: ['groups' => ['holiday:write']],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    order: ['startsAt' => 'DESC'],
    paginationItemsPerPage: 50,
)]
// Nicht im urspruenglichen Planer-Plan explizit gelistet, aber fuer die
// Dashboard-Karte "Offene Urlaubsanträge" (?status=pending&itemsPerPage=1)
// zwingend noetig -- ohne registrierten Filter wuerde der Query-Param
// stillschweigend ignoriert und die Badge-Zahl waere falsch (alle statt nur
// offene Antraege). requestedBy ergaenzt fuers gleiche Muster wie Appointment.
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'requestedBy' => 'exact'])]
class HolidayRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['holiday:read', 'holiday:calendar'])]
    private ?int $id = null;

    /**
     * Wird beim Anlegen automatisch gesetzt (siehe HolidayRequestProcessor), nie
     * vom Client. In der holiday:calendar-Gruppe als IRI (kein Embedding
     * konfiguriert, Standardverhalten dieser API) -- die SPA loest den Namen ueber
     * die bereits vorhandene /users/picker-Liste auf (Muster AppointmentModal,
     * Modul #8), damit fuer den Kalender kein Extra-Endpoint noetig ist.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['holiday:read', 'holiday:calendar'])]
    private ?User $requestedBy = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['holiday:read', 'holiday:write', 'holiday:calendar'])]
    #[Assert\NotNull(message: 'Startdatum ist erforderlich.')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['holiday:read', 'holiday:write', 'holiday:calendar'])]
    #[Assert\NotNull(message: 'Enddatum ist erforderlich.')]
    private ?\DateTimeImmutable $endsAt = null;

    /** Serverseitig berechnet (Werktage Mo-Fr im Zeitraum) -- siehe Processor::calculateRequestedDays(). */
    #[ORM\Column(type: 'float')]
    #[Groups(['holiday:read'])]
    private float $requestedDays = 0.0;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['holiday:read', 'holiday:write'])]
    private ?string $reason = null;

    /** Vertretung waehrend der Abwesenheit -- Freitext, keine Relation. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['holiday:read', 'holiday:write'])]
    private ?string $representative = null;

    /**
     * pending|approved|rejected|withdrawn -- siehe HolidayRequestProcessor::TRANSITIONS.
     * In der write-Gruppe, damit ein Patch den GEWUENSCHTEN Zielstatus
     * uebermitteln kann (z.B. {"status":"approved"}) -- der Processor prueft
     * den Uebergang (alter Status per Direkt-SQL, siehe dort) gegen die
     * Zustandsmaschine und Rechte, BEVOR persistiert wird. Kein Freifahrtschein:
     * Assert\Choice sichert nur den Wertebereich, nicht die Uebergangslogik.
     */
    #[ORM\Column(length: 20)]
    #[Groups(['holiday:read', 'holiday:write'])]
    #[Assert\Choice(choices: ['pending', 'approved', 'rejected', 'withdrawn'], message: 'Ungueltiger Status.')]
    private string $status = 'pending';

    /** Pflicht bei status=rejected (siehe Processor) -- deshalb in write-Gruppe. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['holiday:read', 'holiday:write'])]
    private ?string $rejectedReason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['holiday:read'])]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['holiday:read'])]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column]
    #[Groups(['holiday:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getRequestedDays(): float
    {
        return $this->requestedDays;
    }

    public function setRequestedDays(float $requestedDays): static
    {
        $this->requestedDays = $requestedDays;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getRepresentative(): ?string
    {
        return $this->representative;
    }

    public function setRepresentative(?string $representative): static
    {
        $this->representative = $representative;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRejectedReason(): ?string
    {
        return $this->rejectedReason;
    }

    public function setRejectedReason(?string $rejectedReason): static
    {
        $this->rejectedReason = $rejectedReason;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
