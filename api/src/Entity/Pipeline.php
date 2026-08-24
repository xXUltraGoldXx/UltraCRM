<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\State\PipelineRemoveProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein Vertriebsprozess mit eigenen Phasen.
 *
 * Vorher waren die Phasen feste Konstanten in Deal. Damit liess sich das
 * CRM nur fuer genau einen Prozess benutzen — ein Betrieb, der Neukunden
 * anders fuehrt als Wartungsvertraege, brauchte zwei Systeme.
 *
 * Welche Pipeline die "erste" ist, entscheidet position. Ein eigenes
 * Standard-Kennzeichen gibt es bewusst nicht: es waere eine zweite Quelle
 * fuer dieselbe Aussage und muesste bei jeder Aenderung mitgepflegt werden.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pipeline')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('PERM', 'deals.view')"),
        new Get(security: "is_granted('PERM', 'deals.view')"),
        new Post(security: "is_granted('PERM', 'pipelines.manage')"),
        new Patch(security: "is_granted('PERM', 'pipelines.manage')"),
        // Loeschen ist unumkehrbar und nimmt bei einer Pipeline alle Phasen
        // mit — dieselbe Huerde wie bei Kontakt, Firma und Vorgang (C17).
        new Delete(security: "is_granted('PERM', 'pipelines.delete')", processor: PipelineRemoveProcessor::class),
    ],
    normalizationContext: ['groups' => ['pipeline:read']],
    denormalizationContext: ['groups' => ['pipeline:write']],
    order: ['position' => 'ASC'],
)]
class Pipeline implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['pipeline:read', 'stage:read', 'deal:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte einen Namen angeben.')]
    #[Groups(['pipeline:read', 'pipeline:write', 'stage:read', 'deal:read'])]
    private ?string $name = null;

    #[ORM\Column]
    #[Groups(['pipeline:read', 'pipeline:write'])]
    private int $position = 0;

    /** @var Collection<int, Stage> */
    #[ORM\OneToMany(mappedBy: 'pipeline', targetEntity: Stage::class, cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['pipeline:read'])]
    private Collection $stages;

    #[ORM\Column]
    #[Groups(['pipeline:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->stages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, Stage> */
    public function getStages(): Collection { return $this->stages; }

    public function addStage(Stage $stage): static
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
            $stage->setPipeline($this);
        }

        return $this;
    }
}
