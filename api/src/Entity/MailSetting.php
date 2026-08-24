<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\MailSettingProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A tenant's mail delivery setup. Each tenant maintains its own —
 * deliberately configurable in the application rather than fixed in the
 * .env, so one tenant can't send mail through another's setup.
 *
 * Mailjet works through their SMTP access (in-v3.mailjet.com:587, API key
 * as username, secret as password) — no dedicated package is needed for
 * that.
 *
 * Password/secret are stored encrypted and NEVER returned; the API only
 * reports WHETHER something is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_setting')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(security: "is_granted('ROLE_ADMIN')", processor: MailSettingProcessor::class),
        new Patch(security: "is_granted('ROLE_ADMIN')", processor: MailSettingProcessor::class),
    ],
    normalizationContext: ['groups' => ['mail:read']],
    denormalizationContext: ['groups' => ['mail:write']],
)]
class MailSetting implements TenantOwnedInterface
{
    public const PROVIDERS = ['smtp', 'mailjet'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['mail:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::PROVIDERS, message: 'Unbekannter Versandweg.')]
    #[Groups(['mail:read', 'mail:write'])]
    private string $provider = 'smtp';

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['mail:read', 'mail:write'])]
    private ?string $host = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 65535, notInRangeMessage: 'Der Port muss zwischen 1 und 65535 liegen.')]
    #[Groups(['mail:read', 'mail:write'])]
    private ?int $port = 587;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['mail:read', 'mail:write'])]
    private ?string $username = null;

    /** Encrypted. No read group — never leaves the API. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $secret = null;

    /** Write-only; stored encrypted in $secret. */
    #[Groups(['mail:write'])]
    private ?string $plainSecret = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Bitte eine Absenderadresse angeben.')]
    #[Assert\Email(message: 'Die Absenderadresse ist nicht gültig.')]
    #[Groups(['mail:read', 'mail:write'])]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte einen Absendernamen angeben.')]
    #[Groups(['mail:read', 'mail:write'])]
    private ?string $fromName = null;

    #[ORM\Column]
    #[Groups(['mail:read', 'mail:write'])]
    private bool $active = true;

    /** Tells the UI whether a password is stored — without revealing it. */
    #[Groups(['mail:read'])]
    public function isSecretSet(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $v): static { $this->provider = $v; return $this; }
    public function getHost(): ?string { return $this->host; }
    public function setHost(?string $v): static { $this->host = $v; return $this; }
    public function getPort(): ?int { return $this->port; }
    public function setPort(?int $v): static { $this->port = $v; return $this; }
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $v): static { $this->username = $v; return $this; }
    public function getSecret(): ?string { return $this->secret; }
    public function setSecret(?string $v): static { $this->secret = $v; return $this; }
    public function getPlainSecret(): ?string { return $this->plainSecret; }
    #[Groups(['mail:write'])]
    public function setPlainSecret(?string $v): static { $this->plainSecret = $v; return $this; }
    public function getFromAddress(): ?string { return $this->fromAddress; }
    public function setFromAddress(string $v): static { $this->fromAddress = $v; return $this; }
    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(string $v): static { $this->fromName = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
}
