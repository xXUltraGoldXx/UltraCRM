<?php

namespace App\Security;

use App\Entity\User;

/**
 * Permission catalog for the CRM.
 *
 * Two levels per area: `view` to see, `manage` to change. Anyone with
 * `manage` can also view — otherwise creating a user would always require
 * setting two checkboxes, and one of them would eventually get forgotten.
 *
 * Admins (ROLE_ADMIN) always have everything; this list controls the
 * permissions of everyone else.
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
     * Areas and the levels that actually exist for each — the basis for
     * the freely configurable permission groups.
     *
     * Deliberately NOT three levels everywhere: a report can't be
     * "written", an import can't be "deleted". A toggle with no effect
     * would be a lie in the UI — so this lists, per area, exactly what it
     * supports.
     *
     * "contacts" covers both contacts AND companies: they have shared the
     * same permissions since the beginning, and splitting them would be a
     * business-logic change nobody has asked for.
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

    /** Plain-text labels per area, for the UI. */
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
     * Translates area + level into the permission key the system has
     * always used internally. Returns null for an unknown combination —
     * the toggle is then simply ignored.
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

    /** All permissions with their (German) label, as shown in the UI. */
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

    /** manage implies view. */
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
     * Is this user allowed to do that? Admins always are; otherwise it
     * checks the permission itself or one that implies it.
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

        // Permission group first, then the legacy permission list as a
        // fallback.
        //
        // The fallback stays in place deliberately, until every user has a
        // group: if only the group counted from the moment this shipped,
        // every existing user would suddenly have no permissions at all —
        // including those currently logged in.
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
