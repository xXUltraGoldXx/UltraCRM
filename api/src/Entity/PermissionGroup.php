<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Security\Permissions;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Eine frei benannte Berechtigungsgruppe je Mandant.
 *
 * Alexander (24.08.): "das sollen z.B. Praktikant sein wo man das dann
 * einstellen kann, kunden anlegen ja usw… das man das pro bereich einstellen
 * kann". Der Name gehoert also dem Mandanten, nicht dem System — und die
 * Rechte werden Bereich fuer Bereich gesetzt, nicht als fertige Rolle
 * gewaehlt.
 *
 * Die Rechte liegen als JSON statt in 24 Spalten: Bereiche und Stufen
 * aendern sich mit dem Funktionsumfang, eine Spalte je Kombination waere bei
 * jedem neuen Bereich eine Migration. Geprueft wird beim Setzen
 * (setRechte), sodass nie Unbekanntes in der Datenbank landet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permission_group')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['gruppe:read']],
    denormalizationContext: ['groups' => ['gruppe:write']],
    order: ['name' => 'ASC'],
)]
class PermissionGroup implements TenantOwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['gruppe:read', 'user:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Bitte einen Namen für die Gruppe angeben.')]
    #[Groups(['gruppe:read', 'gruppe:write', 'user:read'])]
    private ?string $name = null;

    /**
     * Rechte je Bereich: ['contacts' => ['lesen' => true, 'schreiben' =>
     * true, 'loeschen' => false], …]. Nicht genannte Bereiche und Stufen
     * gelten als nicht erteilt — Richtung "zu", wie ueberall im System.
     *
     * @var array<string, array<string, bool>>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['gruppe:read', 'gruppe:write'])]
    private array $rechte = [];

    #[ORM\Column]
    #[Groups(['gruppe:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return array<string, array<string, bool>> */
    public function getRechte(): array { return $this->rechte; }

    /**
     * Nimmt nur bekannte Bereiche und Stufen an und wirft alles andere weg.
     *
     * Ohne diese Pruefung koennte ein Client beliebige Schluessel schreiben;
     * sie stuenden dann in der Datenbank, ohne je zu wirken — und beim
     * naechsten Lesen sieht es aus, als waere ein Recht erteilt.
     *
     * @param array<string, array<string, bool>> $rechte
     */
    public function setRechte(array $rechte): static
    {
        $sauber = [];

        foreach ($rechte as $bereich => $stufen) {
            if (!is_string($bereich) || !isset(Permissions::BEREICHE[$bereich]) || !is_array($stufen)) {
                continue;
            }

            foreach ($stufen as $stufe => $erteilt) {
                if (in_array($stufe, Permissions::BEREICHE[$bereich], true) && $erteilt === true) {
                    $sauber[$bereich][$stufe] = true;
                }
            }
        }

        $this->rechte = $sauber;

        return $this;
    }

    /**
     * Uebersetzt die Schalter in die Rechte-Schluessel, mit denen das ganze
     * System schon arbeitet (`contacts.view` …). Dadurch muss keine der 32
     * security-Angaben in den Entities angefasst werden.
     *
     * @return list<string>
     */
    public function alsRechteSchluessel(): array
    {
        $schluessel = [];

        foreach ($this->rechte as $bereich => $stufen) {
            foreach (array_keys($stufen) as $stufe) {
                $recht = Permissions::schluessel($bereich, $stufe);
                if ($recht !== null) {
                    $schluessel[] = $recht;
                }
            }
        }

        return array_values(array_unique($schluessel));
    }
}
