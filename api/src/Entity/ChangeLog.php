<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Change log: who changed which field, how and when.
 *
 * Complements the deletion log (DeletionLog) for everyday changes.
 * Together they answer the question a GDPR Art. 15 access request
 * actually asks: "What has been done with my data?"
 *
 * Read-only — a log that can be altered proves nothing. For the same
 * reason there is no Patch and no Delete.
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

    /** e.g. "contact", "company", "deal". */
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
     * Old and new value as text. Deliberately not exhaustive: the log
     * should make it traceable WHAT changed, not create a second copy of
     * all the data.
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
