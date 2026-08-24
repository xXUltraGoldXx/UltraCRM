<?php

namespace App\Tests\Unit;

use App\Entity\PermissionGroup;
use App\Entity\User;
use App\Security\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * A14, Schritt 1: frei benannte Gruppen mit Rechten je Bereich.
 *
 * Alexander: "das sollen z.B. Praktikant sein wo man das dann einstellen
 * kann, kunden anlegen ja usw… das man das pro bereich einstellen kann".
 */
final class BerechtigungsgruppeTest extends TestCase
{
    private function praktikant(): PermissionGroup
    {
        // Genau Alexanders Beispiel: darf Kunden sehen und anlegen, aber
        // nicht loeschen; vom Vertrieb nur lesen; sonst nichts.
        return (new PermissionGroup())->setName('Praktikant')->setRechte([
            'contacts' => ['lesen' => true, 'schreiben' => true, 'loeschen' => false],
            'deals' => ['lesen' => true],
        ]);
    }

    public function testGruppeErteiltGenauDieGesetztenRechte(): void
    {
        $schluessel = $this->praktikant()->alsRechteSchluessel();

        self::assertContains('contacts.view', $schluessel);
        self::assertContains('contacts.manage', $schluessel);
        self::assertContains('deals.view', $schluessel);

        self::assertNotContains('contacts.delete', $schluessel, 'Loeschen war ausdruecklich aus.');
        self::assertNotContains('deals.manage', $schluessel);
        self::assertNotContains('privacy.view', $schluessel);
    }

    public function testBenutzerMitGruppeBekommtDerenRechte(): void
    {
        $user = (new User())->setUsername('praktikant');
        $user->setPermissionGroup($this->praktikant());

        self::assertTrue(Permissions::hat($user, 'contacts.view'));
        self::assertTrue(Permissions::hat($user, 'contacts.manage'));
        self::assertFalse(Permissions::hat($user, 'contacts.delete'));
        self::assertFalse(Permissions::hat($user, 'privacy.manage'));
    }

    /**
     * Die Regel "schreiben schliesst lesen ein" gilt weiter — sie steckt in
     * Permissions::IMPLIZIT und darf durch die Gruppen nicht verlorengehen.
     */
    public function testSchreibenSchliesstLesenEin(): void
    {
        $gruppe = (new PermissionGroup())->setName('Nur schreiben')->setRechte([
            'contacts' => ['schreiben' => true],
        ]);
        $user = (new User())->setUsername('t');
        $user->setPermissionGroup($gruppe);

        self::assertTrue(Permissions::hat($user, 'contacts.view'), 'Wer aendern darf, muss sehen duerfen.');
    }

    /**
     * Die Rueckfallebene: Bestandsbenutzer ohne Gruppe behalten ihre alten
     * Rechte. Ohne das waeren im Moment der Umstellung alle rechtelos.
     */
    public function testOhneGruppeGiltDieAlteRechteliste(): void
    {
        $user = (new User())->setUsername('bestand');
        $user->setPermissions(['contacts.view', 'reports.view']);

        self::assertTrue(Permissions::hat($user, 'contacts.view'));
        self::assertTrue(Permissions::hat($user, 'reports.view'));
        self::assertFalse(Permissions::hat($user, 'contacts.manage'));
    }

    /**
     * Ist eine Gruppe gesetzt, gilt SIE — nicht die Summe aus beidem. Sonst
     * koennte man einem Benutzer eine engere Gruppe geben und er behielte
     * stillschweigend seine alten, weiteren Rechte.
     */
    public function testGruppeErsetztDieAlteListeVollstaendig(): void
    {
        $user = (new User())->setUsername('umgestellt');
        $user->setPermissions(['privacy.manage', 'importexport.use']);
        $user->setPermissionGroup($this->praktikant());

        self::assertTrue(Permissions::hat($user, 'contacts.view'));
        self::assertFalse(
            Permissions::hat($user, 'privacy.manage'),
            'Die alte Liste darf neben der Gruppe nicht weitergelten.'
        );
        self::assertFalse(Permissions::hat($user, 'importexport.use'));
    }

    public function testUnbekannteBereicheUndStufenWerdenVerworfen(): void
    {
        $gruppe = (new PermissionGroup())->setName('Krumm')->setRechte([
            'ausgedacht' => ['lesen' => true],
            'contacts' => ['fliegen' => true, 'lesen' => true],
            'reports' => ['schreiben' => true], // Auswertung kennt kein Schreiben
        ]);

        self::assertSame(['contacts' => ['lesen' => true]], $gruppe->getRechte());
        self::assertSame(['contacts.view'], $gruppe->alsRechteSchluessel());
    }

    public function testAdminBehaeltAllesAuchOhneGruppe(): void
    {
        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN']);

        self::assertTrue(Permissions::hat($admin, 'privacy.manage'));
        self::assertTrue(Permissions::hat($admin, 'contacts.delete'));
    }

    public function testJederBereichHatNurStufenDieEsGibt(): void
    {
        foreach (Permissions::BEREICHE as $bereich => $stufen) {
            self::assertArrayHasKey($bereich, Permissions::BEREICH_NAMEN, "Klartext fehlt: $bereich");

            foreach ($stufen as $stufe) {
                self::assertNotNull(
                    Permissions::schluessel($bereich, $stufe),
                    sprintf('Bereich "%s" bietet Stufe "%s" an, die zu keinem Recht führt.', $bereich, $stufe)
                );
            }
        }
    }
}
