<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Nachweis einer Loeschung.
 *
 * Enthaelt BEWUSST keine Personendaten des geloeschten Kontakts — sonst
 * waere die Loeschung keine. Festgehalten wird nur, DASS geloescht wurde:
 * Zeitpunkt, ausfuehrender Benutzer, Grund und ein Pseudonym des
 * Datensatzes (Hash aus Id und Mandant), damit sich eine Anfrage spaeter
 * zuordnen laesst, ohne die Daten wiederherstellen zu koennen.
 *
 * Nur lesbar — ein Protokoll, das man aendern kann, belegt nichts.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deletion_log')]
#[ApiResource(
    operations: [new GetCollection()],
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

    /** Was geloescht wurde, z.B. "contact". */
    #[ORM\Column(length: 40)]
    #[Groups(['deletion:read'])]
    private string $subjectType;

    /** Pseudonym statt Klarname oder Id-Verweis. */
    #[ORM\Column(length: 64)]
    #[Groups(['deletion:read'])]
    private string $subjectRef;

    #[ORM\Column(length: 200)]
    #[Groups(['deletion:read'])]
    private string $reason;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['deletion:read'])]
    private ?string $deletedBy = null;

    /** Wie viele abhaengige Datensaetze mitgeloescht wurden. */
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
