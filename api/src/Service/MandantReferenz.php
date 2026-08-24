<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Checks that a record only points to records of the same tenant.
 *
 * The Doctrine filter alone isn't enough for this: it's disabled for
 * ROLE_SUPERADMIN. Without this check, a deal from tenant A could be
 * attached to a stage from tenant B — this was reproduced and confirmed
 * as a real issue. The lesson, seen here for the second time in the
 * codebase: an implicit safeguard isn't one, if a role can bypass it.
 */
final class MandantReferenz
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @param TenantOwnedInterface $datensatz  the record that holds the reference
     * @param TenantOwnedInterface|null $ziel  what it points to
     * @param string $bezeichnung              how the target is named in the error message
     */
    public function pruefe(TenantOwnedInterface $datensatz, ?TenantOwnedInterface $ziel, string $bezeichnung): void
    {
        if ($ziel === null) {
            return;
        }

        $eigener = $datensatz->getTenant() ?? $this->mandantDesBenutzers();

        if ($eigener === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Ohne eigenen Mandanten laesst sich keine %s zuordnen. Bitte als Benutzer eines Mandanten anmelden.',
                $bezeichnung
            ));
        }

        if ($ziel->getTenant()?->getId() !== $eigener->getId()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Die gewaehlte %s gehoert zu einem anderen Mandanten.',
                $bezeichnung
            ));
        }
    }

    private function mandantDesBenutzers(): ?Tenant
    {
        $benutzer = $this->security->getUser();

        return $benutzer instanceof User ? $benutzer->getTenant() : null;
    }
}
