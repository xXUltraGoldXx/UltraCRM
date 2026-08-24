<?php

namespace App\Service;

use App\Entity\Contact;

/**
 * Der Feldabgleich beim Zusammenfuehren zweier Kontakte.
 *
 * Bewusst ohne Datenbank: hier steckt die Regel, die niemanden bewerbbar
 * machen darf, der es vorher nicht war. Sie muss ohne laufende Anwendung
 * pruefbar sein — im Controller waere sie das nicht.
 */
final class ContactMerger
{
    /** Einfache Textfelder, bei denen nur Luecken gefuellt werden. */
    private const FELDER = [
        'FirstName' => 'Vorname',
        'LastName' => 'Nachname',
        'Email' => 'E-Mail',
        'Phone' => 'Telefon',
        'Position' => 'Position',
        'Department' => 'Abteilung',
        'Notes' => 'Notizen',
    ];

    /**
     * Uebernimmt fehlende Angaben der Quelle in das Ziel.
     *
     * @param bool $sichereDublette Nur bei einer sicheren Dublette (gleiche
     *        E-Mail) handelt es sich nachweislich um dieselbe Person. Bei
     *        einer bloss moeglichen Dublette wird die Einwilligung NICHT
     *        uebernommen — sonst erbt eine reale Person die Einwilligung
     *        einer anderen, etwa Vater und Sohn im selben Betrieb.
     *
     * @return string[] Bezeichnungen der uebernommenen Felder
     */
    public function uebernehmen(Contact $ziel, Contact $quelle, bool $sichereDublette): array
    {
        // Vorher festhalten: war einer der beiden bewerbbar?
        $vorherBewerbbar = $ziel->isContactable() || $quelle->isContactable();

        $uebernommen = [];

        foreach (self::FELDER as $feld => $bezeichnung) {
            $lesen = 'get' . $feld;
            $schreiben = 'set' . $feld;
            $zielWert = $ziel->$lesen();
            $quellWert = $quelle->$lesen();

            if (($zielWert === null || $zielWert === '') && $quellWert !== null && $quellWert !== '') {
                $ziel->$schreiben($quellWert);
                $uebernommen[] = $bezeichnung;
            }
        }

        if ($ziel->getCompany() === null && $quelle->getCompany() !== null) {
            $ziel->setCompany($quelle->getCompany());
            $uebernommen[] = 'Firma';
        }

        // Ein Widerruf wird immer uebernommen, auch bei einer nur moeglichen
        // Dublette: er faellt in die sichere Richtung.
        if ($quelle->getConsentWithdrawnAt() !== null && $ziel->getConsentWithdrawnAt() === null) {
            $ziel->setConsentWithdrawnAt($quelle->getConsentWithdrawnAt());
            $uebernommen[] = 'Widerruf';
        }

        // Die Einwilligung dagegen nur bei einer sicheren Dublette — und dann
        // vollstaendig. Wuerde nur consentGivenAt wandern, der offene
        // Bestaetigungslink aber nicht, waere ein Kontakt nach dem
        // Zusammenfuehren bewerbbar, dessen Double-Opt-in nie geklickt wurde.
        if ($sichereDublette && $ziel->getConsentGivenAt() === null && $quelle->getConsentGivenAt() !== null) {
            $ziel->setConsentGivenAt($quelle->getConsentGivenAt());
            $ziel->setConsentText($quelle->getConsentText());
            $ziel->setConfirmToken($quelle->getConfirmToken());
            $ziel->setConsentConfirmedAt($quelle->getConsentConfirmedAt());
            $uebernommen[] = 'Einwilligung';
        }

        // Zusatzfelder: nur fehlende uebernehmen
        $zielDaten = $ziel->getCustomData() ?? [];
        foreach ($quelle->getCustomData() ?? [] as $schluessel => $wert) {
            if (!array_key_exists($schluessel, $zielDaten) || $zielDaten[$schluessel] === null) {
                $zielDaten[$schluessel] = $wert;
                $uebernommen[] = 'Zusatzfeld ' . $schluessel;
            }
        }
        $ziel->setCustomData($zielDaten);

        // Letzte Sicherung gegen kuenftige Aenderungen an den Regeln oben:
        // Zusammenfuehren darf niemanden bewerbbar machen, der es vorher
        // nicht war. Schlaegt das an, liegt ein Programmfehler vor.
        if (!$vorherBewerbbar && $ziel->isContactable()) {
            throw new \LogicException(
                'Zusammenfuehren haette einen Kontakt bewerbbar gemacht, der es vorher nicht war.'
            );
        }

        return $uebernommen;
    }
}
