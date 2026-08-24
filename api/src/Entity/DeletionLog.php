<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Proof that a deletion happened.
 *
 * DELIBERATELY contains no personal data of the deleted contact —
 * otherwise the deletion would not really be one. Only the fact THAT
 * something was deleted is recorded: timestamp, acting user, reason, and
 * a pseudonym of the record (hash of id and tenant), so a later request
 * can be matched up without being able to restore the data.
 *
 * Read-only — a log that can be altered proves nothing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deletion_log')]
#[ApiResource(
    // The deletion log is a compliance record and names the reason and the
    // acting person. It must sit behind the same permission as the change
    // log — not merely "logged in somehow".
    operations: [new GetCollection(security: "is_granted('PERM', 'privacy.view')")],
    normalizationContext: ['groups' => ['deletion:read']],
    order: ['deletedAt' => 'DESC'],
)]
class DeletionLog implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['deletion:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    /** What was deleted, e.g. "contact". */
    #[ORM\Column(length: 40)]
    #[Groups(['deletion:read'])]
    private string $subjectType;

    /** Pseudonym instead of a real name or id reference. */
    #[ORM\Column(length: 64)]
    #[Groups(['deletion:read'])]
    private string $subjectRef;

    #[ORM\Column(length: 200)]
    #[Groups(['deletion:read'])]
    private string $reason;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['deletion:read'])]
    private ?string $deletedBy = null;

    /** How many dependent records were deleted along with it. */
    #[ORM\Column]
    #[Groups(['deletion:read'])]
    private int $relatedCount = 0;

    #[ORM\Column]
    #[Groups(['deletion:read'])]
    private \DateTimeImmutable $deletedAt;

    public function __construct(
        string $subjectType,
        string $subjectRef,
        string $reason,
        ?string $deletedBy,
        int $relatedCount,
    ) {
        $this->subjectType = $subjectType;
        $this->subjectRef = $subjectRef;
        $this->reason = $reason;
        $this->deletedBy = $deletedBy;
        $this->relatedCount = $relatedCount;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectRef(): string { return $this->subjectRef; }
    public function getReason(): string { return $this->reason; }
    public function getDeletedBy(): ?string { return $this->deletedBy; }
    public function getRelatedCount(): int { return $this->relatedCount; }
    public function getDeletedAt(): \DateTimeImmutable { return $this->deletedAt; }
}
