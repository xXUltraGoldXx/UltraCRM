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
 * Ein Mandant (Firma). Verwaltung ist Superadmin-Sache — Mandanten sehen
 * einander nie.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_SUPERADMIN')"),
        new Get(security: "is_granted('ROLE_SUPERADMIN')"),
        new Post(security: "is_granted('ROLE_SUPERADMIN')"),
        new Patch(security: "is_granted('ROLE_SUPERADMIN')"),
        new Delete(security: "is_granted('ROLE_SUPERADMIN')"),
    ],
    normalizationContext: ['groups' => ['tenant:read']],
    denormalizationContext: ['groups' => ['tenant:write']],
)]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['tenant:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['tenant:read', 'tenant:write'])]
    private ?string $name = null;

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Nur Kleinbuchstaben, Ziffern und Bindestriche.')]
    #[Groups(['tenant:read', 'tenant:write'])]
    private ?string $slug = null;

    #[ORM\Column]
    #[Groups(['tenant:read', 'tenant:write'])]
    private bool $active = true;

    #[ORM\Column]
    #[Groups(['tenant:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
