<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aktiviert den Mandanten-Filter fuer jeden Request.
 *
 * Regeln:
 * - Superadmin: Filter bleibt aus, sieht alles (Mandanten-Verwaltung).
 * - Normaler User: Filter auf seinen Mandanten.
 * - Kein User oder User ohne Mandant: Filter auf 0 — mandantengebundene
 *   Daten sind damit grundsaetzlich unsichtbar. Sicherheitsrichtung
 *   "standardmaessig zu", nicht "standardmaessig offen".
 *
 * Prioritaet 4: nach der Firewall (JWT-Auth, Prioritaet 8), vor Controllern.
 */
final class TenantFilterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && in_array('ROLE_SUPERADMIN', $user->getRoles(), true)) {
            return; // Filter bleibt deaktiviert
        }

        $tenantId = 0;
        if ($user instanceof User && $user->getTenant() !== null) {
            $tenantId = (int) $user->getTenant()->getId();
        }

        $this->em->getFilters()->enable('tenant_filter')->setParameter('tenant_id', (string) $tenantId);
    }
}
