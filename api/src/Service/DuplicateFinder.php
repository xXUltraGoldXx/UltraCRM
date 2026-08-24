<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Findet mutmassliche Dubletten im Bestand.
 *
 * Zwei Stufen, bewusst getrennt:
 * - sicher:    gleiche E-Mail. Eine Adresse gehoert genau einer Person.
 * - moeglich:  gleicher Nach- und Vorname in derselben Firma. Das kann
 *              zutreffen, muss aber nicht (Vater und Sohn im Betrieb) —
 *              deshalb wird nie automatisch zusammengefuehrt, sondern nur
 *              vorgeschlagen.
 *
 * Der Vergleich laeuft ueber den Mandantenfilter; ueber Mandantengrenzen
 * hinweg wird nichts verglichen.
 */
final class DuplicateFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{sicherheit: string, grund: string, kontakte: list<Contact>}>
     */
    public function finden(?Tenant $tenant): array
    {
        /** @var Contact[] $alle */
        $alle = $this->em->getRepository(Contact::class)->findBy(
            $tenant !== null ? ['tenant' => $tenant] : [],
            ['lastName' => 'ASC'],
        );

        $gruppen = [];

        // Stufe 1: gleiche E-Mail
        $jeEmail = [];
        foreach ($alle as $k) {
            $mail = mb_strtolower(trim((string) $k->getEmail()));
            if ($mail !== '') {
                $jeEmail[$mail][] = $k;
            }
        }
        foreach ($jeEmail as $mail => $kontakte) {
            if (count($kontakte) > 1) {
                $gruppen[] = [
                    'sicherheit' => 'sicher',
                    'grund' => sprintf('Gleiche E-Mail-Adresse: %s', $mail),
                    'kontakte' => $kontakte,
                ];
            }
        }

        // Stufe 2: gleicher Name in derselben Firma
        $schonGemeldet = [];
        foreach ($gruppen as $g) {
            foreach ($g['kontakte'] as $k) {
                $schonGemeldet[$k->getId()] = true;
            }
        }

        $jeName = [];
        foreach ($alle as $k) {
            if (isset($schonGemeldet[$k->getId()])) {
                continue;
            }

            // Ohne Firmenbezug ist Namensgleichheit kein brauchbares Signal:
            // zwei "Michael Schmidt" ohne Firma sind meistens zwei Menschen.
            // Solche Paare hier zu melden waere die haeufigste Fehlmeldung.
            if ($k->getCompany() === null || trim($k->getDisplayName()) === '') {
                continue;
            }

            $schluessel = mb_strtolower(trim($k->getDisplayName())) . '|' . $k->getCompany()->getId();
            $jeName[$schluessel][] = $k;
        }
        foreach ($jeName as $kontakte) {
            if (count($kontakte) > 1) {
                $gruppen[] = [
                    'sicherheit' => 'moeglich',
                    'grund' => sprintf(
                        'Gleicher Name in derselben Firma: %s',
                        $kontakte[0]->getCompany()->getName()
                    ),
                    'kontakte' => $kontakte,
                ];
            }
        }

        return $gruppen;
    }
}
