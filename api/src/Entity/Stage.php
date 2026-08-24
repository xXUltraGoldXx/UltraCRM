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
 * Eine Phase innerhalb einer Pipeline.
 *
 * `art` ersetzt die frueheren CLOSED_STAGES: nicht der Name entscheidet, ob
 * ein Vorgang abgeschlossen ist, sondern diese Angabe. Sonst haette ein
 * Mandant, der seine Endphase "Auftrag erteilt" nennt, einen Vorgang, der
 * nie als gewonnen zaehlt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stage')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('PERM', 'deals.view')"),
        new Get(security: "is_granted('PERM', 'deals.view')"),
        new Post(security: "is_granted('PERM', 'pipelines.manage')", processor: StageProcessor::class),
        new Patch(security: "is_granted('PERM', 'pipelines.manage')", processor: StageProcessor::class),
        // Loeschen ist unumkehrbar und nimmt bei einer Pipeline alle Phasen
        // mit — dieselbe Huerde wie bei Kontakt, Firma und Vorgang (C17).
        new Delete(security: "is_granted('ROLE_ADMIN')", processor: PipelineRemoveProcessor::class),
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

    /** Was die Phase fuer die Auswertung bedeutet. */
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
     * Zaehlt ein Vorgang in dieser Phase noch als offen?
     *
     * Gefragt wird nach den Abschlussarten, nicht nach OFFEN: ein unbekannter
     * Wert — etwa aus einer Migration oder einem kuenftigen Umbau — soll
     * dazu fuehren, dass ein Vorgang weiter im offenen Geschaeft auftaucht.
     * Andersherum verschwaende er still aus der Liste und zaehlte weder als
     * gewonnen noch als verloren.
     */
    public function istOffen(): bool
    {
        return !in_array($this->art, [self::GEWONNEN, self::VERLOREN], true);
    }
}
