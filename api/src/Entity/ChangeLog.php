<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Aenderungsprotokoll: wer hat wann welches Feld wie geaendert.
 *
 * Ergaenzt das Loeschprotokoll (DeletionLog) um den Alltag. Zusammen
 * beantworten sie die Frage, die bei einer Auskunft nach Art. 15 wirklich
 * gestellt wird: "Was wurde mit meinen Daten gemacht?"
 *
 * Nur lesbar — ein Protokoll, das sich aendern laesst, belegt nichts. Aus
 * demselben Grund gibt es kein Patch und kein Delete.
 */
#[ORM\Entity]
#[ORM\Table(name: 'change_log')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'idx_changelog_subject')]
#[ApiResource(
    operations: [new GetCollection(security: "is_granted('PERM', 'privacy.view')")],
    normalizationContext: ['groups' => ['changelog:read']],
    order: ['changedAt' => 'DESC'],
    paginationItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: ['subjectType' => 'exact', 'subjectId' => 'exact'])]
class ChangeLog implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['changelog:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    /** z.B. "contact", "company", "deal". */
    #[ORM\Column(length: 40)]
    #[Groups(['changelog:read'])]
    private string $subjectType;

    #[ORM\Column]
    #[Groups(['changelog:read'])]
    private int $subjectId;

    #[ORM\Column(length: 60)]
    #[Groups(['changelog:read'])]
    private string $field;

    /**
     * Alter und neuer Wert als Text. Bewusst gekuerzt: das Protokoll soll
     * nachvollziehbar machen, WAS sich geaendert hat, nicht eine zweite
     * Kopie aller Daten anlegen.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['changelog:read'])]
    private ?string $oldValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['changelog:read'])]
    private ?string $newValue = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['changelog:read'])]
    private ?string $changedBy = null;

    #[ORM\Column]
    #[Groups(['changelog:read'])]
    private \DateTimeImmutable $changedAt;

    public function __construct(
        string $subjectType,
        int $subjectId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        ?string $changedBy,
    ) {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->changedBy = $changedBy;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): int { return $this->subjectId; }
    public function getField(): string { return $this->field; }
    public function getOldValue(): ?string { return $this->oldValue; }
    public function getNewValue(): ?string { return $this->newValue; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
