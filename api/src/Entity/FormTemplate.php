<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\FormTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Verbesserungs-Durchlauf Punkt 1: der permissions.js-Katalog kennt KEIN
 * "templates.view" (nur "templates.manage", Label "Formular-Vorlagen
 * gestalten") -- Lesen bleibt deshalb bei IS_AUTHENTICATED_FULLY (kann nicht
 * enger als der Katalog es hergibt gefasst werden, ohne einen neuen, nicht
 * vorgesehenen Rechte-Schluessel zu erfinden). Schreiben/Loeschen verlangen
 * templates.manage.
 */
#[ORM\Entity(repositoryClass: FormTemplateRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN') or 'templates.manage' in user.getPermissions()"),
        new Patch(security: "is_granted('ROLE_ADMIN') or 'templates.manage' in user.getPermissions()"),
        new Delete(security: "is_granted('ROLE_ADMIN') or 'templates.manage' in user.getPermissions()"),
    ],
    normalizationContext: ['groups' => ['template:read']],
    denormalizationContext: ['groups' => ['template:write']],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    order: ['name' => 'ASC'],
)]
class FormTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['template:read', 'submission:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Groups(['template:read', 'template:write', 'submission:read'])]
    #[Assert\NotBlank(message: 'Name ist erforderlich.')]
    private ?string $name = null;

    #[ORM\Column(length: 24, nullable: true)]
    #[Groups(['template:read', 'template:write', 'submission:read'])]
    private ?string $icon = 'file';

    #[ORM\Column(length: 24, nullable: true)]
    #[Groups(['template:read', 'template:write', 'submission:read'])]
    private ?string $color = 'blue';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['template:read', 'template:write'])]
    private ?string $description = null;

    /**
     * Feld-Definitionen des Formulars.
     * Array von Feldern: [{ id, type, label, placeholder, required, width, options[] }]
     */
    #[ORM\Column(name: 'field_schema', type: 'json')]
    #[Groups(['template:read', 'template:write'])]
    private array $schema = [];

    #[ORM\Column]
    #[Groups(['template:read', 'template:write'])]
    private bool $active = true;

    #[ORM\Column]
    #[Groups(['template:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['template:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function setSchema(array $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
