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
 * Ein Kontakt (Person).
 *
 * Die DSGVO-Felder sind bewusst Teil der Entity und nicht Nachtrag:
 * - source        Woher stammt der Datensatz? (Pflicht — Art. 14 verlangt,
 *                 dass die Herkunft benennbar ist)
 * - consentGivenAt/consentText  Einwilligung mit Zeitpunkt und Wortlaut;
 *                 der Wortlaut wird mitgeschrieben, weil ein spaeter
 *                 geaenderter Text die alte Einwilligung nicht belegen kann
 * - consentWithdrawnAt  Widerruf; ab dann darf nicht mehr geworben werden
 * - deleteAfter   Loeschvormerkung (Aufbewahrung endet)
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact')]
#[ApiResource(
    operations: [
        new GetCollection(), new Get(),
        new Post(), new Patch(),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['contact:read']],
    denormalizationContext: ['groups' => ['contact:write']],
    order: ['lastName' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'lastName' => 'ipartial', 'firstName' => 'ipartial',
    'email' => 'ipartial', 'company.name' => 'ipartial', 'status' => 'exact',
    'source' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['lastName', 'createdAt', 'status'])]
class Contact implements TenantOwnedInterface
{
    /** Bearbeitungsstand im Vertrieb. */
    public const STATUS = ['neu', 'in_kontakt', 'qualifiziert', 'kunde', 'kein_interesse'];

    /** Woher der Datensatz stammt — fuer die Auskunftspflicht. */
    public const SOURCES = ['formular', 'telefon', 'messe', 'empfehlung', 'eigene_recherche', 'import', 'sonstiges'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['contact:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte einen Nachnamen angeben.')]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $lastName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Diese E-Mail-Adresse sieht nicht gültig aus.')]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $position = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['contact:read', 'contact:write'])]
    private ?Company $company = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::STATUS, message: 'Unbekannter Status.')]
    #[Groups(['contact:read', 'contact:write'])]
    private string $status = 'neu';

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Bitte angeben, woher der Kontakt stammt.')]
    #[Assert\Choice(choices: self::SOURCES, message: 'Unbekannte Herkunft.')]
    #[Groups(['contact:read', 'contact:write'])]
    private string $source = 'sonstiges';

    #[ORM\Column(nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?\DateTimeImmutable $consentGivenAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $consentText = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?\DateTimeImmutable $consentWithdrawnAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?\DateTimeImmutable $deleteAfter = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $notes = null;

    #[ORM\Column]
    #[Groups(['contact:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Darf dieser Kontakt fuer Werbung angeschrieben werden?
     * Einwilligung erteilt und nicht widerrufen — im Zweifel nein.
     */
    #[Groups(['contact:read'])]
    public function isContactable(): bool
    {
        return $this->consentGivenAt !== null && $this->consentWithdrawnAt === null;
    }

    #[Groups(['contact:read'])]
    public function getDisplayName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $v): static { $this->firstName = $v; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(string $v): static { $this->lastName = $v; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }
    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $v): static { $this->position = $v; return $this; }
    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $v): static { $this->company = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }
    public function getConsentGivenAt(): ?\DateTimeImmutable { return $this->consentGivenAt; }
    public function setConsentGivenAt(?\DateTimeImmutable $v): static { $this->consentGivenAt = $v; return $this; }
    public function getConsentText(): ?string { return $this->consentText; }
    public function setConsentText(?string $v): static { $this->consentText = $v; return $this; }
    public function getConsentWithdrawnAt(): ?\DateTimeImmutable { return $this->consentWithdrawnAt; }
    public function setConsentWithdrawnAt(?\DateTimeImmutable $v): static { $this->consentWithdrawnAt = $v; return $this; }
    public function getDeleteAfter(): ?\DateTimeImmutable { return $this->deleteAfter; }
    public function setDeleteAfter(?\DateTimeImmutable $v): static { $this->deleteAfter = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
