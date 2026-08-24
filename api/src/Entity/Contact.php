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
use App\State\ContactProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A contact (person).
 *
 * The GDPR fields are deliberately part of the entity, not an afterthought:
 * - source        Where the record came from (mandatory — GDPR Art. 14
 *                 requires that the origin be identifiable)
 * - consentGivenAt/consentText  Consent with timestamp and wording; the
 *                 wording is stored because a later-changed text could not
 *                 prove what the original consent covered
 * - consentWithdrawnAt  Withdrawal; from this point on, no more marketing
 *                 is allowed
 * - deleteAfter   Scheduled deletion (retention ends)
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact')]
#[ORM\Index(columns: ['confirm_token'], name: 'idx_contact_confirm_token')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('PERM', 'contacts.view')"),
        new Get(security: "is_granted('PERM', 'contacts.view')"),
        new Post(security: "is_granted('PERM', 'contacts.manage')", processor: ContactProcessor::class),
        new Patch(security: "is_granted('PERM', 'contacts.manage')", processor: ContactProcessor::class),
        new Delete(security: "is_granted('PERM', 'contacts.delete')"),
    ],
    normalizationContext: ['groups' => ['contact:read']],
    denormalizationContext: ['groups' => ['contact:write']],
    order: ['lastName' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'lastName' => 'ipartial', 'firstName' => 'ipartial',
    'email' => 'ipartial', 'company.name' => 'ipartial', 'status' => 'exact',
    'source' => 'exact', 'company' => 'exact', 'department' => 'ipartial',
])]
#[ApiFilter(OrderFilter::class, properties: ['lastName', 'createdAt', 'status', 'primaryContact', 'department'])]
class Contact implements TenantOwnedInterface
{
    /** Sales pipeline status. */
    public const STATUS = ['neu', 'in_kontakt', 'qualifiziert', 'kunde', 'kein_interesse'];

    /** Where the record came from — needed for GDPR disclosure requests. */
    public const SOURCES = ['formular', 'telefon', 'messe', 'empfehlung', 'eigene_recherche', 'import', 'sonstiges'];

    /** Default retention period for the deletion flag, when nobody sets their own. */
    public const STANDARD_LOESCHFRIST = '+30 days';

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

    /**
     * Department within the company (sales, engineering, accounting, …).
     * Free text instead of a fixed list: every company slices its
     * departments differently, so a forced selection rarely fits.
     */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $department = null;

    /**
     * Primary contact person for the company. There can be several people,
     * but only one is the first point of contact — otherwise that fact
     * only lives in the salesperson's head.
     */
    #[ORM\Column]
    #[Groups(['contact:read', 'contact:write'])]
    private bool $primaryContact = false;

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

    /**
     * Confirmed consent (double opt-in). Only once the recipient has
     * clicked the link in the confirmation email is it proven that the
     * address really belongs to them.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['contact:read'])]
    private ?\DateTimeImmutable $consentConfirmedAt = null;

    /** One-time token for the confirmation link. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $notes = null;

    /**
     * Values of the self-defined custom fields, stored as JSON on the
     * record. Validated server-side against CustomFieldDefinition — see
     * CustomFieldValidator.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?array $customData = null;

    #[ORM\Column]
    #[Groups(['contact:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();

        // Default of 30 days when nothing else is configured. This is only
        // a suggestion, not an automatic deletion: it makes the contact
        // show up in /api/privacy/due-deletions for review, actual
        // deletion is still always done by hand via /erase. This applies
        // no matter how a contact is created (form, API, import) — if the
        // client sends its own value (contact:write), the denormalizer
        // overwrites this default normally afterwards.
        $this->deleteAfter = new \DateTimeImmutable(self::STANDARD_LOESCHFRIST);
    }

    /**
     * May this contact be contacted for marketing?
     * Consent given and not withdrawn — when in doubt, no.
     */
    #[Groups(['contact:read'])]
    public function isContactable(): bool
    {
        if ($this->consentGivenAt === null || $this->consentWithdrawnAt !== null) {
            return false;
        }

        // If a confirmation link was sent, consent only counts once the
        // link has been clicked. Contacts without an open token (e.g.
        // signed up at a trade fair) are unaffected.
        return $this->confirmToken === null || $this->consentConfirmedAt !== null;
    }

    #[Groups(['contact:read'])]
    public function isAwaitingConfirmation(): bool
    {
        return $this->confirmToken !== null && $this->consentConfirmedAt === null;
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
    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $v): static { $this->department = $v; return $this; }
    public function isPrimaryContact(): bool { return $this->primaryContact; }
    public function setPrimaryContact(bool $v): static { $this->primaryContact = $v; return $this; }
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
    public function getConsentConfirmedAt(): ?\DateTimeImmutable { return $this->consentConfirmedAt; }
    public function setConsentConfirmedAt(?\DateTimeImmutable $v): static { $this->consentConfirmedAt = $v; return $this; }
    public function getConfirmToken(): ?string { return $this->confirmToken; }
    public function setConfirmToken(?string $v): static { $this->confirmToken = $v; return $this; }
    public function setDeleteAfter(?\DateTimeImmutable $v): static { $this->deleteAfter = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function getCustomData(): ?array { return $this->customData; }
    public function setCustomData(?array $v): static { $this->customData = $v; return $this; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
