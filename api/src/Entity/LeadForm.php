<?php

namespace App\Entity;

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
 * Ein einbettbares Lead-Formular.
 *
 * Der Token bestimmt, bei welchem Mandanten ein Lead landet. Die
 * Mandanten-Id kommt NIE aus dem Request — sonst koennte jeder Leads in
 * fremde Mandanten schreiben.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lead_form')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('PERM', 'leadforms.manage')"),
        new Get(security: "is_granted('PERM', 'leadforms.manage')"),
        new Post(security: "is_granted('PERM', 'leadforms.manage')"),
        new Patch(security: "is_granted('PERM', 'leadforms.manage')"),
        new Delete(security: "is_granted('PERM', 'leadforms.delete')"),
    ],
    normalizationContext: ['groups' => ['leadform:read']],
    denormalizationContext: ['groups' => ['leadform:write']],
)]
class LeadForm implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['leadform:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte einen Namen für das Formular angeben.')]
    #[Groups(['leadform:read', 'leadform:write'])]
    private ?string $name = null;

    /** Zufaellig, nicht erratbar; identifiziert das Formular oeffentlich. */
    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['leadform:read'])]
    private string $token;

    #[ORM\Column]
    #[Groups(['leadform:read', 'leadform:write'])]
    private bool $active = true;

    /**
     * Wortlaut der Einwilligung, dem der Absender zustimmt. Wird bei jedem
     * Lead mitgeschrieben — ein spaeter geaenderter Text darf die frueher
     * erteilte Einwilligung nicht rueckwirkend veraendern.
     */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Bitte den Einwilligungstext angeben.')]
    #[Groups(['leadform:read', 'leadform:write'])]
    private ?string $consentText = null;

    #[ORM\Column]
    #[Groups(['leadform:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->token = bin2hex(random_bytes(24));
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getToken(): string { return $this->token; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
    public function getConsentText(): ?string { return $this->consentText; }
    public function setConsentText(string $v): static { $this->consentText = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
