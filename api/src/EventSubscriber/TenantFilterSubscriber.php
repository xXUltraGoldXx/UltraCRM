<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Activates the tenant filter for every request.
 *
 * Rules:
 * - Superadmin: filter stays off, sees everything (tenant management).
 * - Regular user: filter set to their own tenant.
 * - No user, or a user without a tenant: filter set to 0 — tenant-owned
 *   data is then invisible by default. Security defaults to closed, not
 *   to open.
 *
 * Priority 4: runs after the firewall (JWT auth, priority 8), before
 * controllers.
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
            return; // filter stays disabled
        }

        $tenantId = 0;
        if ($user instanceof User && $user->getTenant() !== null) {
            $tenantId = (int) $user->getTenant()->getId();
        }

        $this->em->getFilters()->enable('tenant_filter')->setParameter('tenant_id', (string) $tenantId);
    }
}
