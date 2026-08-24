<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/** Gemeinsame Basis fuer oeffentliche, nicht angemeldete Endpunkte. */
abstract class AbstractPublicController extends AbstractController
{
    /**
     * Fuehrt eine Abfrage ohne Mandantenfilter aus und stellt ihn danach
     * wieder her.
     *
     * Warum eigens dafuer eine Methode: `enable('tenant_filter')` gibt nach
     * einem `disable()` eine NEUE Filterinstanz zurueck — ohne Parameter. Wer
     * nur disable/enable aufruft, laesst den Filter aktiviert, aber
     * parameterlos zurueck, und die naechste Abfrage auf mandantengebundene
     * Daten stirbt mit "Parameter 'tenant_id' does not exist" (HTTP 500).
     * Genau daran ist die Lead-Annahme gescheitert, nachdem A11 den Abruf der
     * Mailkonfiguration hinzugefuegt hatte (Analyse.md C32).
     *
     * Oeffentliche Endpunkte laufen immer unangemeldet, deshalb ist der
     * richtige Zustand danach: Filter an, Mandant 0 — also grundsaetzlich
     * nichts sichtbar, dieselbe Regel wie im TenantFilterSubscriber.
     *
     * @template T
     * @param callable(): T $abfrage
     * @return T
     */
    protected function ohneMandantenfilter(EntityManagerInterface $em, callable $abfrage): mixed
    {
        $filters = $em->getFilters();
        $warAn = $filters->isEnabled('tenant_filter');

        if ($warAn) {
            $filters->disable('tenant_filter');
        }

        try {
            return $abfrage();
        } finally {
            if ($warAn) {
                $filters->enable('tenant_filter')->setParameter('tenant_id', '0');
            }
        }
    }
}
