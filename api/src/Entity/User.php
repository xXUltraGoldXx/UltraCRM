<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        // Modul #8 Kalender: Mitarbeiter-Dropdown im AppointmentModal braucht eine
        // Liste zum Zuweisen, aber die normale GetCollection ist bewusst ROLE_ADMIN-
        // only (Benutzerverwaltung #4) -- ein Mitarbeiter mit calendar.manage, aber
        // ohne ROLE_ADMIN, wuerde sonst 403 bekommen. Eigene, engere Route statt die
        // bestehende Sicherheit von #4 aufzuweichen: nur id+displayName (eigene
        // Gruppe user:picker, kein email/roles/permissions), jedem Authentifizierten
        // zugaenglich. MUSS vor Get(/users/{id}) stehen: {id} hat kein \d+-
        // Requirement, sonst faengt die Item-Route "/users/picker" faelschlich als
        // id="picker" ab (404 durch Item-Provider statt korrekte Collection).
        new GetCollection(
            uriTemplate: '/users/picker',
            paginationEnabled: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['groups' => ['user:picker']],
        ),
        new Get(security: "is_granted('ROLE_ADMIN') or object == user"),
        new Post(security: "is_granted('ROLE_ADMIN')", processor: \App\State\UserPasswordProcessor::class),
        new Patch(security: "is_granted('ROLE_ADMIN') or object == user", processor: \App\State\UserPasswordProcessor::class),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'user:picker'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $username = null;

    #[ORM\Column(length: 120)]
    #[Groups(['user:read', 'user:write', 'submission:read', 'user:picker'])]
    private ?string $displayName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private array $roles = [];

    /** Feingranulare Modul-Rechte, z.B. ['customers.manage','submissions.view'] */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read', 'user:write'])]
    private array $permissions = [];

    /** Gehashtes Passwort */
    #[ORM\Column]
    private ?string $password = null;

    /** Nur beim Anlegen/Ändern übergeben, wird nie ausgeliefert */
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private bool $active = true;

    /** Modul #9 Urlaubsverwaltung: Kalenderjahr (1.1.-31.12.), ENTSCHIEDEN Default 30. */
    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private int $vacationDaysPerYear = 30;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getVacationDaysPerYear(): int
    {
        return $this->vacationDaysPerYear;
    }

    public function setVacationDaysPerYear(int $vacationDaysPerYear): static
    {
        $this->vacationDaysPerYear = $vacationDaysPerYear;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }
}
