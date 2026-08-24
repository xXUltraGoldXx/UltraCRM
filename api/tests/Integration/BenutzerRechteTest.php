<?php

namespace App\Tests\Integration;

use App\Entity\User;

/**
 * Benutzerverwaltung. Der heikelste Fall: Ein Benutzer darf sich selbst
 * bearbeiten (Anzeigename, Passwort) — er darf sich dabei aber keine Rechte
 * geben, die er nicht hat.
 */
final class BenutzerRechteTest extends IntegrationTestCase
{
    public function testBenutzerKannSichKeineAdminrolleGeben(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        $antwort = $this->anfrage(
            'PATCH',
            '/api/users/' . $mitarbeiter->getId(),
            $mitarbeiter,
            ['roles' => ['ROLE_ADMIN']],
            'application/merge-patch+json',
        );

        $rollen = $this->em->getConnection()
            ->fetchOne('SELECT roles FROM user WHERE id = ?', [$mitarbeiter->getId()]);

        self::assertStringNotContainsString(
            'ROLE_ADMIN',
            (string) $rollen,
            sprintf('Rechteausweitung moeglich (HTTP %d).', $antwort->getStatusCode())
        );
    }

    public function testBenutzerKannSichKeineZusatzrechteGeben(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        $this->anfrage(
            'PATCH',
            '/api/users/' . $mitarbeiter->getId(),
            $mitarbeiter,
            ['permissions' => ['contacts.view', 'privacy.manage', 'importexport.use']],
            'application/merge-patch+json',
        );

        $rechte = (string) $this->em->getConnection()
            ->fetchOne('SELECT permissions FROM user WHERE id = ?', [$mitarbeiter->getId()]);

        self::assertStringNotContainsString('privacy.manage', $rechte);
        self::assertStringNotContainsString('importexport.use', $rechte);
    }

    public function testBenutzerKannSichNichtInEinenAnderenMandantenSetzen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        $this->anfrage(
            'PATCH',
            '/api/users/' . $mitarbeiter->getId(),
            $mitarbeiter,
            ['tenant' => '/api/tenants/' . $b->getId()],
            'application/merge-patch+json',
        );

        $mandantId = $this->em->getConnection()
            ->fetchOne('SELECT tenant_id FROM user WHERE id = ?', [$mitarbeiter->getId()]);

        self::assertSame($a->getId(), (int) $mandantId, 'Der eigene Mandant darf nicht wechselbar sein.');
    }

    public function testEigenerAnzeigenameLaesstSichAendern(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        $antwort = $this->anfrage(
            'PATCH',
            '/api/users/' . $mitarbeiter->getId(),
            $mitarbeiter,
            ['displayName' => 'Neuer Name'],
            'application/merge-patch+json',
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertSame('Neuer Name', $this->em->getConnection()
            ->fetchOne('SELECT display_name FROM user WHERE id = ?', [$mitarbeiter->getId()]));
    }

    public function testAdminDarfRechteVergeben(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        $antwort = $this->anfrage(
            'PATCH',
            '/api/users/' . $mitarbeiter->getId(),
            $admin,
            ['permissions' => ['contacts.view', 'contacts.manage']],
            'application/merge-patch+json',
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertStringContainsString('contacts.manage', (string) $this->em->getConnection()
            ->fetchOne('SELECT permissions FROM user WHERE id = ?', [$mitarbeiter->getId()]));
    }

    public function testMitarbeiterSiehtDieBenutzerlisteNicht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);

        self::assertSame(403, $this->anfrage('GET', '/api/users', $mitarbeiter)->getStatusCode());
    }

    public function testFremderBenutzerIstNichtLesbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $adminB = $this->benutzer($b, 'adminb', [], ['ROLE_ADMIN']);
        $fremd = $this->benutzer($a, 'fremd', ['contacts.view']);

        self::assertSame(404, $this->anfrage('GET', '/api/users/' . $fremd->getId(), $adminB)->getStatusCode());
    }

    public function testPasswortHashVerlaesstDieApiNie(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        $roh = (string) $this->anfrage('GET', '/api/users', $admin)->getContent();

        self::assertStringNotContainsString('$2y$', $roh, 'Kein Passwort-Hash in der Antwort.');
        self::assertStringNotContainsString('"password"', $roh);
    }
}
