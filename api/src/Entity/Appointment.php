<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\AppointmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Kalender-Termin (Modul #8). startsAt/endsAt statt start/end -- MySQL-
 * reservierte Woerter, siehe STATUS.md-Projektfalle (field_schema bei
 * FormTemplate hatte denselben Grund).
 */
#[ORM\Entity(repositoryClass: AppointmentRepository::class)]
#[ORM\Index(columns: ['starts_at', 'ends_at'], name: 'idx_appointment_range')]
#[ORM\Index(columns: ['reminder_at'], name: 'idx_appointment_reminder')]
// Verbesserungs-Durchlauf Punkt 1: calendar.view liest (inkl. reminders_due --
// "Reminders-/Range-Route: view reicht", kein eigener Ownership-Scope noetig,
// der Kalender ist bewusst ein Gemeinschafts-Kalender wie schon der
// /holiday_requests/calendar-Unterweg aus Modul #9), calendar.manage schreibt/loescht.
#[ApiResource(
    operations: [
        new GetCollection(
            provider: \App\State\AppointmentRangeProvider::class,
            security: "is_granted('ROLE_ADMIN') or 'calendar.view' in user.getPermissions() or 'calendar.manage' in user.getPermissions()",
        ),
        // Dashboard "Anstehende Erinnerungen" (Schritt "Erinnerungen"). MUSS vor
        // Get(/appointments/{id}) stehen -- {id} hat kein \d+-Requirement, sonst
        // faengt die Item-Route "reminders_due" faelschlich als id ab (siehe
        // derselbe Fund bei User::/users/picker).
        new GetCollection(
            uriTemplate: '/appointments/reminders_due',
            paginationEnabled: false,
            provider: \App\State\AppointmentReminderProvider::class,
            security: "is_granted('ROLE_ADMIN') or 'calendar.view' in user.getPermissions() or 'calendar.manage' in user.getPermissions()",
        ),
        new Get(security: "is_granted('ROLE_ADMIN') or 'calendar.view' in user.getPermissions() or 'calendar.manage' in user.getPermissions()"),
        new Post(processor: \App\State\AppointmentProcessor::class, security: "is_granted('ROLE_ADMIN') or 'calendar.manage' in user.getPermissions()"),
        new Patch(processor: \App\State\AppointmentProcessor::class, security: "is_granted('ROLE_ADMIN') or 'calendar.manage' in user.getPermissions()"),
        new Delete(security: "is_granted('ROLE_ADMIN') or 'calendar.manage' in user.getPermissions()"),
    ],
    normalizationContext: ['groups' => ['appointment:read']],
    denormalizationContext: ['groups' => ['appointment:write']],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    order: ['startsAt' => 'ASC'],
    paginationItemsPerPage: 50,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'assignedTo' => 'exact',
    'customer' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['startsAt'])]
class Appointment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['appointment:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['appointment:read', 'appointment:write'])]
    #[Assert\NotBlank(message: 'Titel ist erforderlich.')]
    private ?string $title = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['appointment:read', 'appointment:write'])]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['appointment:read', 'appointment:write'])]
    private ?User $assignedTo = null;

    /** Wird beim Anlegen automatisch gesetzt (siehe AppointmentProcessor), nie vom Client. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['appointment:read'])]
    private ?User $createdBy = null;

    #[ORM\Column]
    #[Groups(['appointment:read', 'appointment:write'])]
    #[Assert\NotNull(message: 'Startzeitpunkt ist erforderlich.')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column]
    #[Groups(['appointment:read', 'appointment:write'])]
    #[Assert\NotNull(message: 'Endzeitpunkt ist erforderlich.')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column]
    #[Groups(['appointment:read', 'appointment:write'])]
    private bool $allDay = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['appointment:read', 'appointment:write'])]
    private ?string $note = null;

    /** Farbwort-Konvention wie FormTemplate::color (frontend/src/fieldTypes.js TEMPLATE_COLORS) */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['appointment:read', 'appointment:write'])]
    private ?string $color = 'blue';

    /**
     * Eigenstaendiges, von startsAt UNABHAENGIGES Erinnerungsfeld -- z.B.
     * "Wartung faellig in 6 Monaten": Termin liegt in der Zukunft, die
     * Erinnerung soll aber schon frueher aufploppen. Kein Mailer im Projekt
     * (geprueft) -- Auswertung ausschliesslich per Dashboard-Query
     * (reminderAt <= jetzt AND startsAt > jetzt), siehe DashboardView.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['appointment:read', 'appointment:write'])]
    private ?\DateTimeImmutable $reminderAt = null;

    #[ORM\Column]
    #[Groups(['appointment:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

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

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getReminderAt(): ?\DateTimeImmutable
    {
        return $this->reminderAt;
    }

    public function setReminderAt(?\DateTimeImmutable $reminderAt): static
    {
        $this->reminderAt = $reminderAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
