<?php

namespace App\Tests\Integration;

use App\Entity\DeletionLog;
use App\Entity\LeadForm;
use App\Entity\Tenant;

/**
 * Die Endpunkte, die bisher durch kein Testnetz liefen: Loeschprotokoll,
 * Passwortwechsel, Mandantenverwaltung, Lead-Formulare und der
 * Bestaetigungslink des Double-Opt-in.
 */
final class EndpunktAbsicherungTest extends IntegrationTestCase
{
    // -------------------------------------------------------- Loeschprotokoll

    /**
     * Das Loeschprotokoll ist ein Datenschutz-Nachweis. Es gehoert an
     * dasselbe Recht wie das Aenderungsprotokoll, nicht an "irgendwie
     * angemeldet".
     */
    public function testLoeschprotokollBrauchtDasDatenschutzrecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $mitarbeiter = $this->benutzer($a, 'mitarbeiter', ['contacts.view']);
        $this->loeschEintrag($a, 'Wunsch des Betroffenen');

        self::assertSame(403, $this->anfrage('GET', '/api/deletion_logs', $mitarbeiter)->getStatusCode());
    }

    public function testMitDatenschutzrechtIstDasLoeschprotokollLesbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $berechtigt = $this->benutzer($a, 'datenschutz', ['privacy.view']);
        $this->loeschEintrag($a, 'Wunsch des Betroffenen');

        $antwort = $this->anfrage('GET', '/api/deletion_logs', $berechtigt);

        self::assertSame(200, $antwort->getStatusCode());
        self::assertSame(1, $this->inhalt($antwort)['totalItems']);
    }

    public function testLoeschprotokollBleibtImEigenenMandanten(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $this->loeschEintrag($a, 'Wunsch des Betroffenen');
        $nutzerB = $this->benutzer($b, 'b1', ['privacy.view']);

        $inhalt = $this->inhalt($this->anfrage('GET', '/api/deletion_logs', $nutzerB));

        self::assertSame(0, $inhalt['totalItems']);
    }

    // ----------------------------------------------------------- Passwort

    public function testPasswortwechselVerlangtDasAltePasswort(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'nutzer', ['contacts.view']);
        $vorher = $this->passwortHash($nutzer->getId());

        $antwort = $this->anfrage('POST', '/api/me/password', $nutzer, [
            'currentPassword' => 'falsch-geraten',
            'newPassword' => 'NeuesPasswort2026',
        ], 'application/json');

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame($vorher, $this->passwortHash($nutzer->getId()), 'Das Passwort darf sich nicht geaendert haben.');
    }

    public function testZuKurzesPasswortWirdAbgelehnt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'nutzer', ['contacts.view']);

        $antwort = $this->anfrage('POST', '/api/me/password', $nutzer, [
            'currentPassword' => 'Test!2026',
            'newPassword' => 'kurz',
        ], 'application/json');

        self::assertSame(422, $antwort->getStatusCode());
    }

    public function testEigenesPasswortLaesstSichAendern(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'nutzer', ['contacts.view']);
        $vorher = $this->passwortHash($nutzer->getId());

        $antwort = $this->anfrage('POST', '/api/me/password', $nutzer, [
            'currentPassword' => 'Test!2026',
            'newPassword' => 'EinNeuesLangesPasswort',
        ], 'application/json');

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertNotSame($vorher, $this->passwortHash($nutzer->getId()));
    }

    // ------------------------------------------------------- Mandanten

    public function testMandantenverwaltungIstNurFuerDenSuperadmin(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        self::assertSame(403, $this->anfrage('GET', '/api/tenants', $admin)->getStatusCode());
        self::assertSame(403, $this->anfrage('POST', '/api/tenants', $admin, [
            'name' => 'Selbst angelegt',
            'slug' => 'selbst',
            'active' => true,
        ])->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant'));
    }

    // ---------------------------------------------------- Lead-Formulare

    /**
     * Wer den Token eines fremden Formulars kennt, kann Kontakte in dessen
     * Mandanten einschleusen. Er darf deshalb nirgends durchscheinen.
     */
    public function testFremdeFormulartokenSindNichtSichtbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $formularA = $this->formular($a);
        $nutzerB = $this->benutzer($b, 'b1', ['leadforms.manage']);

        $antwort = $this->anfrage('GET', '/api/lead_forms', $nutzerB);

        self::assertSame(200, $antwort->getStatusCode());
        self::assertSame(0, $this->inhalt($antwort)['totalItems']);
        self::assertStringNotContainsString($formularA->getToken(), (string) $antwort->getContent());
    }

    public function testFormulareBrauchenIhrRecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'ohne', ['contacts.view']);

        self::assertSame(403, $this->anfrage('GET', '/api/lead_forms', $ohneRecht)->getStatusCode());
    }

    // ------------------------------------------------- Bestaetigungslink

    /**
     * Der Klick auf den Bestaetigungslink ist der Moment, in dem aus einem
     * Formular-Lead ein bewerbbarer Kontakt wird. Vorher nie geprueft.
     */
    public function testBestaetigungslinkGibtDenKontaktFrei(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $formular = $this->formular($a);

        $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formular->getToken(),
            'lastName' => 'Klickt',
            'email' => 'klickt@test.invalid',
            'consent' => true,
        ], 'application/json');

        $token = $this->em->getConnection()
            ->fetchOne('SELECT confirm_token FROM contact WHERE last_name = ?', ['Klickt']);
        self::assertNotEmpty($token, 'Vorbedingung: es gibt einen Bestaetigungstoken.');

        $antwort = $this->anfrage('GET', '/api/public/leads/confirm/' . $token);
        self::assertSame(200, $antwort->getStatusCode());

        // Bewusst ueber DBAL: nach der anonymen Anfrage steht der
        // Mandantenfilter auf 0, ein Repository-Aufruf faende hier nichts.
        $zeile = $this->em->getConnection()->fetchAssociative(
            'SELECT confirm_token, consent_confirmed_at, consent_given_at FROM contact WHERE last_name = ?',
            ['Klickt']
        );

        self::assertNotFalse($zeile);
        self::assertNotNull($zeile['consent_confirmed_at'], 'Der Klick muss die Bestaetigung festhalten.');
        self::assertNotNull($zeile['consent_given_at']);
    }

    public function testUnbekannterBestaetigungslinkLaeuftInsLeere(): void
    {
        $this->mandant('Mandant A', 'a');

        $antwort = $this->anfrage('GET', '/api/public/leads/confirm/ausgedachter-token');

        self::assertSame(404, $antwort->getStatusCode());
    }

    // ------------------------------------------------------------ Helfer

    private function loeschEintrag(Tenant $mandant, string $grund): void
    {
        $eintrag = new DeletionLog('contact', hash('sha256', 'test'), $grund, 'tester', 0);
        $eintrag->setTenant($mandant);

        $this->em->persist($eintrag);
        $this->em->flush();
    }

    private function formular(Tenant $mandant): LeadForm
    {
        $formular = (new LeadForm())
            ->setName('Kontaktformular')
            ->setActive(true)
            ->setConsentText('Ich bin einverstanden.');
        $formular->setTenant($mandant);

        $this->em->persist($formular);
        $this->em->flush();

        return $formular;
    }

    private function passwortHash(?int $id): string
    {
        return (string) $this->em->getConnection()
            ->fetchOne('SELECT password FROM user WHERE id = ?', [$id]);
    }
}
