<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\State\PipelineRemoveProcessor;
use App\State\StageProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A stage within a pipeline.
 *
 * `art` (kind) replaces the former CLOSED_STAGES: it's this field, not the
 * name, that decides whether a deal is closed. Otherwise a tenant naming
 * their final stage "Order placed" would have a deal that never counts as
 * won.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stage')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('PERM', 'deals.view')"),
        new Get(security: "is_granted('PERM', 'deals.view')"),
        new Post(security: "is_granted('PERM', 'pipelines.manage')", processor: StageProcessor::class),
        new Patch(security: "is_granted('PERM', 'pipelines.manage')", processor: StageProcessor::class),
        // Deletion is irreversible and takes all of a pipeline's stages
        // with it — the same safeguard used for contact, company, and
        // deal deletion.
        new Delete(security: "is_granted('PERM', 'pipelines.delete')", processor: PipelineRemoveProcessor::class),
    ],
    normalizationContext: ['groups' => ['stage:read']],
    denormalizationContext: ['groups' => ['stage:write']],
    order: ['position' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['pipeline' => 'exact', 'art' => 'exact'])]
class Stage implements TenantOwnedInterface
{
    public const OFFEN = 'offen';
    public const GEWONNEN = 'gewonnen';
    public const VERLOREN = 'verloren';

    /** What the stage means for reporting. */
    public const ARTEN = [self::OFFEN, self::GEWONNEN, self::VERLOREN];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['stage:read', 'deal:read', 'pipeline:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pipeline::class, inversedBy: 'stages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Bitte eine Pipeline angeben.')]
    #[Groups(['stage:read', 'stage:write', 'deal:read'])]
    private ?Pipeline $pipeline = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Bitte einen Namen angeben.')]
    #[Groups(['stage:read', 'stage:write', 'deal:read', 'pipeline:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::ARTEN, message: 'Unbekannte Art der Phase.')]
    #[Groups(['stage:read', 'stage:write', 'deal:read', 'pipeline:read'])]
    private string $art = self::OFFEN;

    #[ORM\Column]
    #[Groups(['stage:read', 'stage:write', 'deal:read', 'pipeline:read'])]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getPipeline(): ?Pipeline { return $this->pipeline; }
    public function setPipeline(?Pipeline $v): static { $this->pipeline = $v; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getArt(): string { return $this->art; }
    public function setArt(string $v): static { $this->art = $v; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    /**
     * Does a deal in this stage still count as open?
     *
     * This checks against the closed kinds, not against OFFEN (open): an
     * unknown value — say from a migration or a future rework — should
     * make a deal keep showing up in the open pipeline. The other way
     * round it would silently disappear from the list, counting as
     * neither won nor lost.
     */
    public function istOffen(): bool
    {
        return !in_array($this->art, [self::GEWONNEN, self::VERLOREN], true);
    }
}
