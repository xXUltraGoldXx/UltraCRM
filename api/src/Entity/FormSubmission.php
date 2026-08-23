<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\FormSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Verbesserungs-Durchlauf Punkt 1 (Muster HolidayRequest, Modul #9):
 * submissions.view liest fremde Eintraege, eigene (createdBy) sind IMMER
 * lesbar/aenderbar/loeschbar unabhaengig von Rechten -- genau wie beim
 * eigenen Urlaubsantrag. submissions.manage (Katalog-Label "Scheine
 * ausfuellen & bearbeiten") ist das Ausfuell-Recht: noetig zum Neuanlegen
 * (Post) und zum Bearbeiten/Loeschen FREMDER Eintraege. Scoping der
 * Collection uebernimmt SubmissionScopeProvider (Muster
 * HolidayRequestScopeProvider).
 */
#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(provider: \App\State\SubmissionScopeProvider::class),
        new Get(security: "is_granted('ROLE_ADMIN') or 'submissions.view' in user.getPermissions() or 'submissions.manage' in user.getPermissions() or (object.getCreatedBy() and object.getCreatedBy().getId() == user.getId())"),
        new Post(security: "is_granted('ROLE_ADMIN') or 'submissions.manage' in user.getPermissions()", processor: \App\State\SubmissionProcessor::class),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or 'submissions.manage' in user.getPermissions() or (object.getCreatedBy() and object.getCreatedBy().getId() == user.getId())",
            processor: \App\State\SubmissionProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_ADMIN') or 'submissions.manage' in user.getPermissions() or (object.getCreatedBy() and object.getCreatedBy().getId() == user.getId())"),
    ],
    normalizationContext: ['groups' => ['submission:read']],
    denormalizationContext: ['groups' => ['submission:write']],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
    order: ['createdAt' => 'DESC'],
    paginationItemsPerPage: 25,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'template' => 'exact',
    'customer' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'status'])]
class FormSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['submission:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['submission:read', 'submission:write'])]
    private ?FormTemplate $template = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['submission:read', 'submission:write'])]
    private ?Customer $customer = null;

    /** Eingetragene Werte: fieldId => Wert (Wert kann String, Bool oder Array für Tabellen sein) */
    #[ORM\Column(type: 'json')]
    #[Groups(['submission:read', 'submission:write'])]
    private array $data = [];

    /** draft | completed */
    #[ORM\Column(length: 20)]
    #[Groups(['submission:read', 'submission:write'])]
    private string $status = 'draft';

    /** Momentaufnahme des Vorlagennamens, falls die Vorlage später umbenannt/gelöscht wird */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['submission:read'])]
    private ?string $templateName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['submission:read'])]
    private ?User $createdBy = null;

    #[ORM\Column]
    #[Groups(['submission:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['submission:read'])]
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

    public function getTemplate(): ?FormTemplate
    {
        return $this->template;
    }

    public function setTemplate(?FormTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }

    public function setTemplateName(?string $templateName): static
    {
        $this->templateName = $templateName;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

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
