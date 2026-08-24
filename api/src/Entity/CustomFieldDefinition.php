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
 * A self-defined custom field.
 *
 * Why a definition plus a JSON value on the record instead of real columns:
 * adding columns would mean every tenant alters the schema — with multiple
 * tenants sharing one database, that is not viable. The price is that
 * filtering by custom fields is only possible to a limited extent; that is
 * an acceptable trade-off for supplementary data.
 *
 * Values are validated server-side against this definition — an open JSON
 * field without validation would invite garbage data.
 */
#[ORM\Entity]
#[ORM\Table(name: 'custom_field_definition')]
#[ORM\UniqueConstraint(name: 'uniq_feld_je_mandant', columns: ['tenant_id', 'entity_type', 'field_key'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['cfd:read']],
    denormalizationContext: ['groups' => ['cfd:write']],
    order: ['position' => 'ASC'],
)]
class CustomFieldDefinition implements TenantOwnedInterface
{
    /** Which kind of record this field applies to. */
    public const ENTITIES = ['contact', 'company', 'deal'];

    public const TYPES = ['text', 'zahl', 'datum', 'auswahl', 'janein'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['cfd:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::ENTITIES, message: 'Unbekannter Datensatztyp.')]
    #[Groups(['cfd:read', 'cfd:write'])]
    private string $entityType = 'contact';

    /**
     * Technical key inside the JSON. Derived from the label and then NEVER
     * changed afterwards — otherwise existing records lose their value.
     */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Bitte einen Feldschlüssel angeben.')]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{1,59}$/', message: 'Nur Kleinbuchstaben, Ziffern und Unterstrich, beginnend mit einem Buchstaben.')]
    #[Groups(['cfd:read', 'cfd:write'])]
    private ?string $fieldKey = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte eine Bezeichnung angeben.')]
    #[Groups(['cfd:read', 'cfd:write'])]
    private ?string $label = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES, message: 'Unbekannter Feldtyp.')]
    #[Groups(['cfd:read', 'cfd:write'])]
    private string $type = 'text';

    /** Only used when type is "auswahl" (select): the allowed values. */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['cfd:read', 'cfd:write'])]
    private ?array $options = null;

    #[ORM\Column]
    #[Groups(['cfd:read', 'cfd:write'])]
    private bool $required = false;

    #[ORM\Column]
    #[Groups(['cfd:read', 'cfd:write'])]
    private int $position = 0;

    #[ORM\Column]
    #[Groups(['cfd:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validate(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->type === 'auswahl' && count($this->options ?? []) < 2) {
            $context->buildViolation('Eine Auswahl braucht mindestens zwei Möglichkeiten.')
                ->atPath('options')
                ->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $v): static { $this->entityType = $v; return $this; }
    public function getFieldKey(): ?string { return $this->fieldKey; }
    public function setFieldKey(string $v): static { $this->fieldKey = $v; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(string $v): static { $this->label = $v; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }
    public function getOptions(): ?array { return $this->options; }
    public function setOptions(?array $v): static { $this->options = $v; return $this; }
    public function isRequired(): bool { return $this->required; }
    public function setRequired(bool $v): static { $this->required = $v; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
