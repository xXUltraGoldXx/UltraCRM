<?php

namespace App\Service;

use App\Entity\Contact;

/**
 * Field-by-field reconciliation when merging two contacts.
 *
 * Deliberately without a database: this is where the rule lives that
 * merging must never make a contact reachable who wasn't reachable
 * before. It has to be testable without a running application — it
 * wouldn't be, in a controller.
 */
final class ContactMerger
{
    /** Simple text fields where only gaps get filled in. */
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
     * Copies missing data from the source into the target.
     *
     * @param bool $sichereDublette Only with a confirmed duplicate (same
     *        email) is it provably the same person. For a merely possible
     *        duplicate, consent is NOT copied over — otherwise one real
     *        person would inherit another's consent, e.g. father and son
     *        at the same company.
     *
     * @return string[] Labels of the fields that were copied
     */
    public function uebernehmen(Contact $ziel, Contact $quelle, bool $sichereDublette): array
    {
        // Record beforehand: was either contact already reachable?
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

        // A withdrawal is always copied over, even for a merely possible
        // duplicate: it errs on the safe side.
        if ($quelle->getConsentWithdrawnAt() !== null && $ziel->getConsentWithdrawnAt() === null) {
            $ziel->setConsentWithdrawnAt($quelle->getConsentWithdrawnAt());
            $uebernommen[] = 'Widerruf';
        }

        // Consent, on the other hand, is only copied for a confirmed
        // duplicate — and then completely. If only consentGivenAt moved
        // over but not the pending confirmation link, a contact would end
        // up reachable after the merge whose double opt-in was never
        // actually clicked.
        if ($sichereDublette && $ziel->getConsentGivenAt() === null && $quelle->getConsentGivenAt() !== null) {
            $ziel->setConsentGivenAt($quelle->getConsentGivenAt());
            $ziel->setConsentText($quelle->getConsentText());
            $ziel->setConfirmToken($quelle->getConfirmToken());
            $ziel->setConsentConfirmedAt($quelle->getConsentConfirmedAt());
            $uebernommen[] = 'Einwilligung';
        }

        // Custom fields: only copy over what's missing
        $zielDaten = $ziel->getCustomData() ?? [];
        foreach ($quelle->getCustomData() ?? [] as $schluessel => $wert) {
            if (!array_key_exists($schluessel, $zielDaten) || $zielDaten[$schluessel] === null) {
                $zielDaten[$schluessel] = $wert;
                $uebernommen[] = 'Zusatzfeld ' . $schluessel;
            }
        }
        $ziel->setCustomData($zielDaten);

        // Final safeguard against future changes to the rules above:
        // merging must never make a contact reachable who wasn't
        // reachable before. If this triggers, it's a bug.
        if (!$vorherBewerbbar && $ziel->isContactable()) {
            throw new \LogicException(
                'Zusammenfuehren haette einen Kontakt bewerbbar gemacht, der es vorher nicht war.'
            );
        }

        return $uebernommen;
    }
}
