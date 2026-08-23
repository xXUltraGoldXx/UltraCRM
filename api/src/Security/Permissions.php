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
    public const ACTIVITIES_VIEW = 'activities.view';
    public const ACTIVITIES_MANAGE = 'activities.manage';
    public const LEADFORMS_MANAGE = 'leadforms.manage';
    public const PRIVACY_VIEW = 'privacy.view';
    public const PRIVACY_MANAGE = 'privacy.manage';
    public const REPORTS_VIEW = 'reports.view';
    public const IMPORT_EXPORT = 'importexport.use';

    /** Alle Rechte mit deutscher Bezeichnung — Grundlage der Oberflaeche. */
    public const KATALOG = [
        'Kontakte und Firmen' => [
            self::CONTACTS_VIEW => 'Ansehen',
            self::CONTACTS_MANAGE => 'Anlegen und ändern',
        ],
        'Vertrieb' => [
            self::DEALS_VIEW => 'Vorgänge ansehen',
            self::DEALS_MANAGE => 'Vorgänge anlegen und ändern',
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

        $eigene = $user->getPermissions();
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
