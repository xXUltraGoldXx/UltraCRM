<?php

namespace App\Tests\Integration;

use App\Entity\MailSetting;
use App\Entity\Tenant;

/**
 * Die Mailkonfiguration enthaelt Zugangsdaten. Entsprechend wird hier
 * geprueft, wer sie sehen darf, dass das Geheimnis die API nie verlaesst und
 * dass es beim Speichern nicht versehentlich verlorengeht.
 */
final class MailEinstellungTest extends IntegrationTestCase
{
    public function testNurAdminSiehtDieEinstellung(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $this->einstellung($a, 'mail.example.invalid');
        $mitarbeiter = $this->benutzer($a, 'sachbearbeiter', ['contacts.view', 'contacts.manage']);

        self::assertSame(403, $this->anfrage('GET', '/api/mail_settings', $mitarbeiter)->getStatusCode());
    }

    public function testDasGeheimnisVerlaesstDieApiNie(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $this->einstellung($a, 'mail.example.invalid', 'streng-geheim-123');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        $antwort = $this->anfrage('GET', '/api/mail_settings', $admin);
        $roh = (string) $antwort->getContent();

        self::assertSame(200, $antwort->getStatusCode());
        self::assertStringNotContainsString('streng-geheim-123', $roh, 'Das Passwort darf nie ausgeliefert werden.');
        self::assertStringNotContainsString('"secret"', $roh);
        self::assertStringContainsString('secretSet', $roh, 'Dass etwas hinterlegt ist, darf die Oberflaeche wissen.');
    }

    /**
     * Der Fall, an dem eine Oberflaeche leicht scheitert: Beim Speichern
     * einer Aenderung bleibt das Passwortfeld leer, weil es nie ausgeliefert
     * wurde. Das darf das hinterlegte Geheimnis nicht loeschen.
     */
    public function testSpeichernOhnePasswortLaesstDasGeheimnisStehen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $einstellung = $this->einstellung($a, 'mail.example.invalid', 'bleibt-erhalten');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        $vorher = $this->em->getConnection()
            ->fetchOne('SELECT secret FROM mail_setting WHERE id = ?', [$einstellung->getId()]);

        $antwort = $this->anfrage(
            'PATCH',
            '/api/mail_settings/' . $einstellung->getId(),
            $admin,
            ['fromName' => 'Neuer Absendername'],
            'application/merge-patch+json',
        );
        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());

        $nachher = $this->em->getConnection()
            ->fetchOne('SELECT secret FROM mail_setting WHERE id = ?', [$einstellung->getId()]);

        self::assertSame($vorher, $nachher, 'Ein leeres Passwortfeld darf nichts loeschen.');
        self::assertNotEmpty($nachher);
    }

    public function testFremdeEinstellungIstUnsichtbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $this->einstellung($a, 'mail-a.example.invalid');
        $adminB = $this->benutzer($b, 'adminb', [], ['ROLE_ADMIN']);

        $inhalt = $this->inhalt($this->anfrage('GET', '/api/mail_settings', $adminB));

        self::assertSame(0, $inhalt['totalItems'], 'Mandant B darf den Versandweg von A nicht sehen.');
    }

    public function testTestmailBrauchtEineGueltigeAdresse(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $this->einstellung($a, 'mail.example.invalid');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        $antwort = $this->anfrage('POST', '/api/mail/test', $admin, ['to' => 'keine-adresse'], 'application/json');

        self::assertSame(422, $antwort->getStatusCode());
    }

    public function testOhneVersandwegSagtDasDieApiDeutlich(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);

        $antwort = $this->anfrage(
            'POST',
            '/api/mail/test',
            $admin,
            ['to' => 'jemand@example.invalid'],
            'application/json',
        );

        self::assertSame(404, $antwort->getStatusCode());
    }

    /**
     * SSRF: Ein Mailserver steht im Internet, nicht im eigenen Netz. Ein
     * Host im privaten Bereich muss abgelehnt werden, sonst laesst sich das
     * CRM als Sonde gegen interne Dienste benutzen (frueherer Review-Befund).
     */
    public function testInterneAdresseWirdAbgelehnt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);
        $einstellung = $this->einstellung($a, '127.0.0.1');

        $antwort = $this->anfrage(
            'POST',
            '/api/mail/test',
            $admin,
            ['to' => 'jemand@example.invalid'],
            'application/json',
        );

        self::assertNotSame(200, $antwort->getStatusCode(), 'Ein interner Host darf nicht angesprochen werden.');
        self::assertStringNotContainsString(
            'Testmail verschickt',
            (string) $antwort->getContent(),
            sprintf('Host %s haette abgelehnt werden muessen.', $einstellung->getHost() ?? '')
        );
    }

    private function einstellung(Tenant $mandant, string $host, ?string $geheimnis = 'geheim'): MailSetting
    {
        $einstellung = (new MailSetting())
            ->setProvider('smtp')
            ->setHost($host)
            ->setPort(587)
            ->setUsername('benutzer')
            ->setFromAddress('crm@example.invalid')
            ->setFromName('CRM')
            ->setActive(true);
        $einstellung->setTenant($mandant);

        // Verschluesselt wird im MailSettingProcessor, nicht in der Entity.
        // Der Testaufbau muss denselben Weg nehmen, sonst steht in der
        // Datenbank gar kein Geheimnis und der Test prueft ins Leere.
        if ($geheimnis !== null) {
            $einstellung->setSecret(
                self::getContainer()->get(\App\Service\SecretBox::class)->encrypt($geheimnis)
            );
        }

        $this->em->persist($einstellung);
        $this->em->flush();

        return $einstellung;
    }
}
