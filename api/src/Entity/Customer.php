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
use App\Repository\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Verbesserungs-Durchlauf Punkt 1 (projektweite permissions[]-Luecke aus dem
 * #8-Review): Get/GetCollection bleiben BEWUSST bei IS_AUTHENTICATED_FULLY
 * (keine customers.view-Pflicht) -- CustomerPicker.vue wird breit verwendet
 * (AppointmentModal::calendar.manage, FillSubmissionView::submissions.manage),
 * und das Planer-eigene Testprofil "eingeschraenkt" (nur calendar.view +
 * Ausfuellrecht, explizit OHNE customers.*) muss diese Wege weiter nutzen
 * koennen. customers.view/manage bleiben reine Nav-Gates fuer die volle
 * CustomersView-Verwaltungsseite (wie NAV_PERMISSIONS es schon vorsieht),
 * kein API-Read-Gate -- Kundendaten (Firma/Kontakt/Adresse) sind zudem
 * operative Alltagsdaten, keine Zugangsdaten wie bei User. Post/Patch
 * (echtes Anlegen/Aendern von Stammdaten) verlangen dagegen customers.manage;
 * Delete bleibt wie bisher ROLE_ADMIN-only (unveraendert).
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN') or 'customers.manage' in user.getPermissions()"),
        new Patch(security: "is_granted('ROLE_ADMIN') or 'customers.manage' in user.getPermissions()"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['customer:read']],
    denormalizationContext: ['groups' => ['customer:write']],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    order: ['company' => 'ASC'],
    paginationItemsPerPage: 20,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'company' => 'ipartial',
    'contact' => 'ipartial',
    'city' => 'ipartial',
    'location' => 'ipartial',
])]
#[ApiFilter(OrderFilter::class, properties: ['company', 'city', 'createdAt'])]
class Customer implements TenantOwnedInterface
{
    /**
     * Mandanten-Bindung (Paket 2). Kein Serialisierungs-Group: der Mandant
     * kommt NIE aus dem Request (TenantAssignListener) und wird auch nicht
     * ausgeliefert.
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['customer:read', 'submission:read'])]
    private ?int $id = null;

    /** Firmenname */
    #[ORM\Column(length: 180)]
    #[Groups(['customer:read', 'customer:write', 'submission:read'])]
    #[Assert\NotBlank(message: 'Firmenname ist erforderlich.')]
    private ?string $company = null;

    /** Ansprechpartner */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $contact = null;

    /** Standort / Filiale */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $location = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    #[Assert\Email(message: 'Ungültige E-Mail-Adresse.')]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $street = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $zipCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['customer:read', 'customer:write', 'submission:read'])]
    private ?string $city = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['customer:read', 'customer:write'])]
    private ?string $notes = null;

    #[ORM\Column]
    #[Groups(['customer:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function setZipCode(?string $zipCode): static
    {
        $this->zipCode = $zipCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
