<?php

namespace App\Security;

use App\Entity\User;

/**
 * Rechtekatalog des CRM.
 *
 * Zwei Stufen je Bereich: `view` zum Ansehen, `manage` zum Aendern. Wer
 * `manage` hat, kann auch ansehen — sonst muesste man beim Anlegen eines
 * Benutzers immer zwei Haken setzen und wuerde den einen vergessen.
 *
 * Admins (ROLE_ADMIN) haben immer alles; die Liste steuert die Rechte der
 * uebrigen Mitarbeiter.
 */
final class Permissions
{
    public const CONTACTS_VIEW = 'contacts.view';
    public const CONTACTS_MANAGE = 'contacts.manage';
    public const DEALS_VIEW = 'deals.view';
    public const DEALS_MANAGE = 'deals.manage';
    public const PIPELINES_MANAGE = 'pipelines.manage';
    public const ACTIVITIES_VIEW = 'activities.view';
    public const ACTIVITIES_MANAGE = 'activities.manage';
    public const LEADFORMS_MANAGE = 'leadforms.manage';
    public const PRIVACY_VIEW = 'privacy.view';
    public const PRIVACY_MANAGE = 'privacy.manage';
    public const REPORTS_VIEW = 'reports.view';
    public const IMPORT_EXPORT = 'importexport.use';

    /**
     * Bereiche und die Stufen, die es dort tatsaechlich gibt — Grundlage der
     * frei anlegbaren Berechtigungsgruppen (A14).
     *
     * Bewusst NICHT ueberall drei Stufen: Eine Auswertung laesst sich nicht
     * "schreiben", ein Import nicht "loeschen". Ein Schalter ohne Wirkung
     * waere eine Luege in der Oberflaeche — deshalb steht hier je Bereich,
     * was er kennt.
     *
     * "contacts" umfasst Kontakte UND Firmen: die teilen sich seit Beginn
     * dieselben Rechte, und sie zu trennen waere eine fachliche Aenderung,
     * die niemand verlangt hat.
     *
     * @var array<string, list<string>>
     */
    public const BEREICHE = [
        'contacts' => ['lesen', 'schreiben', 'loeschen'],
        'deals' => ['lesen', 'schreiben', 'loeschen'],
        'activities' => ['lesen', 'schreiben', 'loeschen'],
        'pipelines' => ['schreiben', 'loeschen'],
        'leadforms' => ['schreiben', 'loeschen'],
        'privacy' => ['lesen', 'schreiben', 'loeschen'],
        'reports' => ['lesen'],
        'importexport' => ['lesen'],
    ];

    /** Klartext je Bereich fuer die Oberflaeche. */
    public const BEREICH_NAMEN = [
        'contacts' => 'Kontakte und Firmen',
        'deals' => 'Vertrieb',
        'activities' => 'Aktivitäten',
        'pipelines' => 'Pipelines und Phasen',
        'leadforms' => 'Lead-Formulare',
        'privacy' => 'Datenschutz',
        'reports' => 'Auswertung',
        'importexport' => 'Import und Export',
    ];

    /**
     * Uebersetzt Bereich + Stufe in den Rechte-Schluessel, mit dem das
     * System seit jeher arbeitet. Gibt es die Kombination nicht, kommt null
     * zurueck — der Schalter wird dann schlicht ignoriert.
     */
    public static function schluessel(string $bereich, string $stufe): ?string
    {
        return match ([$bereich, $stufe]) {
            ['contacts', 'lesen'] => self::CONTACTS_VIEW,
            ['contacts', 'schreiben'] => self::CONTACTS_MANAGE,
            ['contacts', 'loeschen'] => 'contacts.delete',
            ['deals', 'lesen'] => self::DEALS_VIEW,
            ['deals', 'schreiben'] => self::DEALS_MANAGE,
            ['deals', 'loeschen'] => 'deals.delete',
            ['activities', 'lesen'] => self::ACTIVITIES_VIEW,
            ['activities', 'schreiben'] => self::ACTIVITIES_MANAGE,
            ['activities', 'loeschen'] => 'activities.delete',
            ['pipelines', 'schreiben'] => self::PIPELINES_MANAGE,
            ['pipelines', 'loeschen'] => 'pipelines.delete',
            ['leadforms', 'schreiben'] => self::LEADFORMS_MANAGE,
            ['leadforms', 'loeschen'] => 'leadforms.delete',
            ['privacy', 'lesen'] => self::PRIVACY_VIEW,
            ['privacy', 'schreiben'] => self::PRIVACY_MANAGE,
            ['privacy', 'loeschen'] => 'privacy.delete',
            ['reports', 'lesen'] => self::REPORTS_VIEW,
            ['importexport', 'lesen'] => self::IMPORT_EXPORT,
            default => null,
        };
    }

    /** Alle Rechte mit deutscher Bezeichnung — Grundlage der Oberflaeche. */
    public const KATALOG = [
        'Kontakte und Firmen' => [
            self::CONTACTS_VIEW => 'Ansehen',
            self::CONTACTS_MANAGE => 'Anlegen und ändern',
        ],
        'Vertrieb' => [
            self::DEALS_VIEW => 'Vorgänge ansehen',
            self::DEALS_MANAGE => 'Vorgänge anlegen und ändern',
            self::PIPELINES_MANAGE => 'Pipelines und Phasen einrichten',
        ],
        'Aktivitäten' => [
            self::ACTIVITIES_VIEW => 'Ansehen',
            self::ACTIVITIES_MANAGE => 'Anlegen und abhaken',
        ],
        'Lead-Formulare' => [
            self::LEADFORMS_MANAGE => 'Formulare verwalten',
        ],
        'Datenschutz' => [
            self::PRIVACY_VIEW => 'Auskunft erstellen',
            self::PRIVACY_MANAGE => 'Widerruf und Löschung',
        ],
        'Auswertung' => [
            self::REPORTS_VIEW => 'Auswertung ansehen',
        ],
        'Daten' => [
            self::IMPORT_EXPORT => 'Import und Export',
        ],
    ];

    /** manage schliesst view ein. */
    private const IMPLIZIT = [
        self::CONTACTS_MANAGE => self::CONTACTS_VIEW,
        self::DEALS_MANAGE => self::DEALS_VIEW,
        self::PIPELINES_MANAGE => self::DEALS_VIEW,
        self::ACTIVITIES_MANAGE => self::ACTIVITIES_VIEW,
        self::PRIVACY_MANAGE => self::PRIVACY_VIEW,
    ];

    /** @return list<string> */
    public static function alle(): array
    {
        $alle = [];
        foreach (self::KATALOG as $rechte) {
            $alle = [...$alle, ...array_keys($rechte)];
        }

        return $alle;
    }

    /**
     * Darf dieser Benutzer das? Admins immer; sonst das Recht selbst oder
     * ein Recht, das es einschliesst.
     */
    public static function hat(?User $user, string $recht): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)
            || in_array('ROLE_SUPERADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Erst die Berechtigungsgruppe (A14), dann die alte Rechteliste.
        //
        // Die Rueckfallebene bleibt bewusst bestehen, bis jeder Benutzer eine
        // Gruppe hat: Wuerde hier sofort nur noch die Gruppe zaehlen, waeren
        // im Moment der Umstellung alle Bestandsbenutzer ohne Rechte —
        // einschliesslich derer, die gerade angemeldet sind.
        $eigene = $user->getPermissionGroup()?->alsRechteSchluessel()
            ?? $user->getPermissions();

        if (in_array($recht, $eigene, true)) {
            return true;
        }

        foreach (self::IMPLIZIT as $starkes => $schwaches) {
            if ($schwaches === $recht && in_array($starkes, $eigene, true)) {
                return true;
            }
        }

        return false;
    }
}
