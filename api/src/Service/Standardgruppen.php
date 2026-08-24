<?php

namespace App\Service;

use App\Entity\PermissionGroup;
use App\Entity\Tenant;
use App\Security\Permissions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Die Vorlagen, mit denen ein Mandant startet.
 *
 * Alexanders Aufzaehlung (24.08.): "Nur lese zugriff. Nur updaten. Anlegen
 * aber nicht löschen. Dann voll zugriff kein admin. Und dann der admin
 * konto." Daraus vier Gruppen — das Admin-Konto ist keine Gruppe, sondern
 * eine Rolle und bleibt davon unberuehrt.
 *
 * Ausdruecklich VORLAGEN, keine festen Rollen: Alexander will Gruppen frei
 * benennen ("Praktikant") und je Bereich einstellen. Diese vier sind nur da,
 * damit niemand vor einer leeren Liste sitzt — sie lassen sich umbenennen,
 * aendern und loeschen wie jede selbst angelegte Gruppe.
 *
 * "Loeschen" im Bereich Datenschutz vergibt KEINE Vorlage: dahinter steht
 * die endgueltige Loeschung eines Menschen nach Art. 17 (Alexander:
 * "einstellbar die Berechtigung und ja erstmal nur admin").
 */
final class Standardgruppen
{
    /** Bereiche des Tagesgeschaefts — ohne Datenschutz und Einrichtung. */
    private const ALLTAG = ['contacts', 'deals', 'activities'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Legt fehlende Vorlagen an. Mehrfach aufrufbar: was es schon gibt
     * (am Namen erkannt), bleibt unangetastet — auch wenn jemand die Rechte
     * inzwischen geaendert hat.
     *
     * @return list<string> Namen der neu angelegten Gruppen
     */
    public function anlegen(Tenant $mandant): array
    {
        $vorhanden = [];
        foreach ($this->em->getRepository(PermissionGroup::class)->findBy(['tenant' => $mandant]) as $gruppe) {
            $vorhanden[mb_strtolower((string) $gruppe->getName())] = true;
        }

        $angelegt = [];
        foreach ($this->vorlagen() as $name => $rechte) {
            if (isset($vorhanden[mb_strtolower($name)])) {
                continue;
            }

            $gruppe = (new PermissionGroup())->setName($name)->setRechte($rechte);
            $gruppe->setTenant($mandant);
            $this->em->persist($gruppe);
            $angelegt[] = $name;
        }

        return $angelegt;
    }

    /** @return array<string, array<string, array<string, bool>>> */
    public function vorlagen(): array
    {
        return [
            'Nur Lesen' => $this->stufen(lesen: true),
            'Lesen und Ändern' => $this->stufen(lesen: true, schreiben: true),
            'Anlegen, nicht löschen' => $this->stufen(lesen: true, schreiben: true),
            'Voller Zugriff (kein Admin)' => $this->voll(),
        ];
    }

    /**
     * Die Alltagsbereiche mit denselben Stufen, dazu Auswertung lesen.
     *
     * "Lesen und Aendern" und "Anlegen, nicht loeschen" sind technisch
     * gleich: Anlegen und Aendern ist im System dieselbe Stufe (schreiben).
     * Beide Vorlagen bleiben trotzdem stehen, weil Alexander sie getrennt
     * benannt hat und weil ihr Unterschied im Alltag der ist, was NICHT
     * dabei ist — naemlich Loeschen.
     *
     * @return array<string, array<string, bool>>
     */
    private function stufen(bool $lesen = false, bool $schreiben = false): array
    {
        $rechte = [];
        foreach (self::ALLTAG as $bereich) {
            $rechte[$bereich] = array_filter([
                'lesen' => $lesen,
                'schreiben' => $schreiben,
            ]);
        }
        $rechte['reports'] = ['lesen' => true];

        return $rechte;
    }

    /**
     * Alles, was ohne Adminrechte vergeben werden kann — einschliesslich
     * Loeschen im Tagesgeschaeft, aber ohne Datenschutz-Loeschung.
     *
     * @return array<string, array<string, bool>>
     */
    private function voll(): array
    {
        $rechte = [];
        foreach (Permissions::BEREICHE as $bereich => $stufen) {
            foreach ($stufen as $stufe) {
                if ($bereich === 'privacy' && $stufe === 'loeschen') {
                    continue;
                }
                $rechte[$bereich][$stufe] = true;
            }
        }

        return $rechte;
    }
}
