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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Firma (Organisation). Kontakte haengen optional an einer Firma. */
#[ORM\Entity]
#[ORM\Table(name: 'company')]
#[ApiResource(
    operations: [
        new GetCollection(), new Get(),
        new Post(), new Patch(),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['company:read']],
    denormalizationContext: ['groups' => ['company:write']],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'ipartial', 'city' => 'ipartial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Company implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['company:read', 'contact:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Bitte einen Firmennamen angeben.')]
    #[Groups(['company:read', 'company:write', 'contact:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $street = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $zipCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'Bitte eine gültige Adresse angeben, z. B. https://beispiel.de')]
    #[Groups(['company:read', 'company:write'])]
    private ?string $website = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $notes = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Contact::class)]
    private Collection $contacts;

    #[ORM\Column]
    #[Groups(['company:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $v): static { $this->street = $v; return $this; }
    public function getZipCode(): ?string { return $this->zipCode; }
    public function setZipCode(?string $v): static { $this->zipCode = $v; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }
    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $v): static { $this->website = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }
    public function getContacts(): Collection { return $this->contacts; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
