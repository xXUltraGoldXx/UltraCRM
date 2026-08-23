<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Aktivitaet: was mit einem Kontakt passiert ist oder passieren soll.
 *
 * Zwei Naturen in einer Entity, bewusst nicht getrennt:
 * - Vergangenes (Anruf, Notiz, E-Mail) -> Verlauf
 * - Vorgemerktes (Aufgabe mit dueAt)   -> Wiedervorlage
 * Getrennte Entities haetten dieselben Felder und denselben Bezug; der
 * Unterschied ist nur, ob ein Faelligkeitsdatum gesetzt ist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activity')]
#[ORM\Index(columns: ['due_at'], name: 'idx_activity_due')]
#[ApiResource(
    operations: [
        new GetCollection(), new Get(),
        new Post(), new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['activity:read']],
    denormalizationContext: ['groups' => ['activity:write']],
    order: ['dueAt' => 'ASC', 'createdAt' => 'DESC'],
    paginationItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'contact' => 'exact', 'deal' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['done'])]
#[ApiFilter(DateFilter::class, properties: ['dueAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['dueAt'])]
#[ApiFilter(OrderFilter::class, properties: ['dueAt', 'createdAt'])]
class Activity implements TenantOwnedInterface
{
    public const TYPES = ['anruf', 'notiz', 'aufgabe', 'email', 'termin'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['activity:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES, message: 'Unbekannte Art.')]
    #[Groups(['activity:read', 'activity:write'])]
    private string $type = 'notiz';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Bitte einen Betreff angeben.')]
    #[Groups(['activity:read', 'activity:write'])]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?string $body = null;

    /** Gesetzt = Wiedervorlage, leer = reiner Verlaufseintrag. */
    #[ORM\Column(nullable: true)]
    #[Groups(['activity:read', 'activity:write'])]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column]
    #[Groups(['activity:read', 'activity:write'])]
    private bool $done = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['activity:read'])]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['activity:read', 'activity:write'])]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Deal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['activity:read', 'activity:write'])]
    private ?Deal $deal = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['activity:read', 'activity:write'])]
    private ?User $assignedTo = null;

    #[ORM\Column]
    #[Groups(['activity:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Faellig und noch offen — die Grundlage fuer "Heute fällig". */
    #[Groups(['activity:read'])]
    public function isOverdue(): bool
    {
        return !$this->done
            && $this->dueAt !== null
            && $this->dueAt < new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }
    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(string $v): static { $this->subject = $v; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $v): static { $this->body = $v; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $v): static { $this->dueAt = $v; return $this; }
    public function isDone(): bool { return $this->done; }

    public function setDone(bool $v): static
    {
        $this->done = $v;
        // Erledigt-Zeitpunkt mitfuehren, ohne dass der Client ihn schickt.
        $this->doneAt = $v ? ($this->doneAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }

    public function getDoneAt(): ?\DateTimeImmutable { return $this->doneAt; }
    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $v): static { $this->contact = $v; return $this; }
    public function getDeal(): ?Deal { return $this->deal; }
    public function setDeal(?Deal $v): static { $this->deal = $v; return $this; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $v): static { $this->assignedTo = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
