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
 * A freely named permission group per tenant.
 *
 * The name belongs to the tenant, not the system — for example an intern
 * role where only certain areas (e.g. creating customers) are enabled.
 * Permissions are granted area by area, not chosen as a ready-made role.
 *
 * Permissions are stored as JSON instead of in dozens of columns: areas
 * and levels change with the feature set, and a column per combination
 * would mean a migration for every new area. They are validated on write
 * (setRechte), so nothing unknown ever ends up in the database.
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
     * Permissions per area: ['contacts' => ['lesen' => true, 'schreiben' =>
     * true, 'loeschen' => false], …]. Areas and levels that are not listed
     * count as not granted — defaulting to "closed", as everywhere else
     * in the system.
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
     * Accepts only known areas and levels and discards everything else.
     *
     * Without this check a client could write arbitrary keys; they would
     * sit in the database without ever taking effect — and on the next
     * read it would look as if a permission had been granted.
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
     * Translates the toggles into the permission keys the rest of the
     * system already works with (`contacts.view` …). This means none of
     * the security expressions across the entities need to be touched.
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
