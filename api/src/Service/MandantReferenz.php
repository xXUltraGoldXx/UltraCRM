<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Prueft, dass ein Datensatz nur auf Datensaetze desselben Mandanten zeigt.
 *
 * Der Doctrine-Filter allein genuegt dafuer nicht: fuer ROLE_SUPERADMIN ist
 * er abgeschaltet. Ohne diese Pruefung liess sich ein Vorgang aus Mandant A
 * an eine Phase aus Mandant B haengen — nachgestellt am 24.08. mit Vorgang 7
 * und Phase 26 (Analyse.md C24). Derselbe Fehler wie C18, nur an anderer
 * Stelle: ein impliziter Schutz ist keiner, wenn eine Rolle ihn aushebelt.
 */
final class MandantReferenz
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @param TenantOwnedInterface $datensatz  der Datensatz, der verweist
     * @param TenantOwnedInterface|null $ziel  worauf er zeigt
     * @param string $bezeichnung              wie das Ziel in der Meldung heisst
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
