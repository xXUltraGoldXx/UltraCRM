<?php

namespace App\Entity;

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
 * Ein Verkaufsvorgang.
 *
 * Der Wert liegt als DECIMAL in der Datenbank und wird als String
 * uebertragen — Geldbetraege gehoeren nicht in einen Float, sonst summieren
 * sich Rundungsfehler ueber die Pipeline auf.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deal')]
#[ApiResource(
    operations: [
        new GetCollection(), new Get(),
        new Post(), new Patch(),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['deal:read']],
    denormalizationContext: ['groups' => ['deal:write']],
    order: ['position' => 'ASC', 'createdAt' => 'DESC'],
    paginationItemsPerPage: 200,
)]
#[ApiFilter(SearchFilter::class, properties: ['stage' => 'exact', 'title' => 'ipartial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'value', 'expectedCloseAt'])]
class Deal implements TenantOwnedInterface
{
    public const STAGES = ['neu', 'qualifiziert', 'angebot', 'verhandlung', 'gewonnen', 'verloren'];
    public const CLOSED_STAGES = ['gewonnen', 'verloren'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['deal:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Bitte einen Titel angeben.')]
    #[Groups(['deal:read', 'deal:write'])]
    private ?string $title = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Der Wert darf nicht negativ sein.')]
    #[Groups(['deal:read', 'deal:write'])]
    private ?string $value = null;

    #[ORM\Column(length: 3)]
    #[Groups(['deal:read', 'deal:write'])]
    private string $currency = 'EUR';

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::STAGES, message: 'Unbekannte Phase.')]
    #[Groups(['deal:read', 'deal:write'])]
    private string $stage = 'neu';

    /** Reihenfolge innerhalb der Phase (Kanban-Sortierung). */
    #[ORM\Column]
    #[Groups(['deal:read', 'deal:write'])]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['deal:read', 'deal:write'])]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['deal:read', 'deal:write'])]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['deal:read', 'deal:write'])]
    private ?User $owner = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['deal:read', 'deal:write'])]
    private ?\DateTimeImmutable $expectedCloseAt = null;

    /**
     * Grund bei "verloren". Ohne Begruendung lernt niemand etwas aus einem
     * verlorenen Vorgang, deshalb ist sie dort Pflicht (siehe validate()).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['deal:read', 'deal:write'])]
    private ?string $lostReason = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['deal:read'])]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    #[Groups(['deal:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validate(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->stage === 'verloren' && trim((string) $this->lostReason) === '') {
            $context->buildViolation('Bitte kurz angeben, warum der Vorgang verloren ging.')
                ->atPath('lostReason')
                ->addViolation();
        }
    }

    #[Groups(['deal:read'])]
    public function isOpen(): bool
    {
        return !in_array($this->stage, self::CLOSED_STAGES, true);
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $v): static { $this->value = $v; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): static { $this->currency = $v; return $this; }
    public function getStage(): string { return $this->stage; }

    public function setStage(string $v): static
    {
        $this->stage = $v;
        // Abschlusszeitpunkt mitfuehren, ohne dass der Client daran denken muss.
        $this->closedAt = in_array($v, self::CLOSED_STAGES, true)
            ? ($this->closedAt ?? new \DateTimeImmutable())
            : null;

        return $this;
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $v): static { $this->contact = $v; return $this; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $v): static { $this->company = $v; return $this; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $v): static { $this->owner = $v; return $this; }
    public function getExpectedCloseAt(): ?\DateTimeImmutable { return $this->expectedCloseAt; }
    public function setExpectedCloseAt(?\DateTimeImmutable $v): static { $this->expectedCloseAt = $v; return $this; }
    public function getLostReason(): ?string { return $this->lostReason; }
    public function setLostReason(?string $v): static { $this->lostReason = $v; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
