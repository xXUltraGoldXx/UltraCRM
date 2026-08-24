<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Counts submission attempts for the rate limit.
 *
 * Only a hash of the IP is stored (with APP_SECRET as pepper), never the
 * address itself: recognizing repeat attempts is enough for abuse
 * prevention, and a plaintext log would send the wrong signal for a CRM
 * with GDPR obligations. Deliberately no ApiResource — this data is not
 * meant to be exposed to anyone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lead_attempt')]
#[ORM\Index(columns: ['ip_hash', 'created_at'], name: 'idx_attempt_lookup')]
class LeadAttempt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $ipHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $ipHash)
    {
        $this->ipHash = $ipHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getIpHash(): string { return $this->ipHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
