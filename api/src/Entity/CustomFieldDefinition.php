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
 * Ein selbst angelegtes Zusatzfeld.
 *
 * Warum eine Definition und ein JSON-Wert am Datensatz statt echter Spalten:
 * Spalten anzulegen hiesse, dass jeder Mandant das Schema veraendert — bei
 * mehreren Mandanten in einer Datenbank ist das nicht tragbar. Der Preis ist,
 * dass sich nach Zusatzfeldern nur eingeschraenkt filtern laesst; das ist fuer
 * Zusatzangaben vertretbar.
 *
 * Die Werte werden serverseitig gegen diese Definition geprueft — ein freies
 * JSON-Feld ohne Pruefung waere eine Einladung fuer Datenmuell.
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
    /** Fuer welche Art von Datensatz das Feld gilt. */
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
     * Technischer Schluessel im JSON. Wird aus der Bezeichnung abgeleitet und
     * danach NICHT mehr geaendert — sonst verlieren bestehende Datensaetze
     * ihren Wert.
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

    /** Nur bei Typ "auswahl": die zulaessigen Werte. */
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
