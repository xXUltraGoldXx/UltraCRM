<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Zaehlt Einsendeversuche fuer das Rate-Limit.
 *
 * Gespeichert wird NUR ein Hash der IP (mit APP_SECRET als Pepper), nie die
 * Adresse selbst: fuer die Missbrauchsabwehr genuegt Wiedererkennung, und
 * ein Klartext-Protokoll waere bei einem CRM mit DSGVO-Anspruch das falsche
 * Signal. Bewusst keine ApiResource — diese Daten gehoeren niemandem.
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
