<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\Permissions;
use PHPUnit\Framework\TestCase;

/** Der Rechtekatalog entscheidet, wer was sieht — hier die Regeln als Test. */
final class PermissionsTest extends TestCase
{
    private function benutzer(array $rechte, array $rollen = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setRoles($rollen);
        $u->setPermissions($rechte);

        return $u;
    }

    public function testOhneRechteDarfNichts(): void
    {
        $u = $this->benutzer([]);

        foreach (Permissions::alle() as $recht) {
            self::assertFalse(Permissions::hat($u, $recht), $recht . ' darf nicht erlaubt sein.');
        }
    }

    public function testAdminDarfAlles(): void
    {
        $u = $this->benutzer([], ['ROLE_ADMIN']);

        foreach (Permissions::alle() as $recht) {
            self::assertTrue(Permissions::hat($u, $recht), $recht . ' muss für Admins gelten.');
        }
    }

    public function testManageSchliesstViewEin(): void
    {
        $u = $this->benutzer([Permissions::CONTACTS_MANAGE]);

        self::assertTrue(Permissions::hat($u, Permissions::CONTACTS_MANAGE));
        self::assertTrue(
            Permissions::hat($u, Permissions::CONTACTS_VIEW),
            'Wer ändern darf, darf auch ansehen — sonst vergisst man beim Anlegen den zweiten Haken.'
        );
    }

    public function testViewSchliesstManageNichtEin(): void
    {
        $u = $this->benutzer([Permissions::CONTACTS_VIEW]);

        self::assertTrue(Permissions::hat($u, Permissions::CONTACTS_VIEW));
        self::assertFalse(
            Permissions::hat($u, Permissions::CONTACTS_MANAGE),
            'Ansehen darf nicht zum Ändern berechtigen.'
        );
    }

    public function testRechteWirkenNichtBereichsuebergreifend(): void
    {
        $u = $this->benutzer([Permissions::CONTACTS_MANAGE]);

        self::assertFalse(Permissions::hat($u, Permissions::DEALS_VIEW));
        self::assertFalse(Permissions::hat($u, Permissions::PRIVACY_MANAGE));
        self::assertFalse(Permissions::hat($u, Permissions::IMPORT_EXPORT));
    }

    public function testOhneBenutzerNichts(): void
    {
        self::assertFalse(Permissions::hat(null, Permissions::CONTACTS_VIEW));
    }

    public function testKatalogIstVollstaendigUndEindeutig(): void
    {
        $alle = Permissions::alle();

        self::assertSame($alle, array_unique($alle), 'Kein Recht darf doppelt vorkommen.');
        self::assertContains(Permissions::PRIVACY_MANAGE, $alle);
        self::assertGreaterThanOrEqual(10, count($alle));
    }
}
