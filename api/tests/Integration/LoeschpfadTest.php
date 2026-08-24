<?php

namespace App\Tests\Integration;

use App\Entity\Activity;
use App\Entity\ChangeLog;
use App\Entity\Contact;
use App\Entity\Tenant;

/**
 * Loeschung nach Art. 17 DSGVO. Der Kontakt muss vollstaendig verschwinden —
 * einschliesslich Verlauf und Aenderungsprotokoll. Analyse.md C15 hielt genau
 * hier eine Luecke fest: die Telefonnummer blieb im Protokoll stehen.
 */
final class LoeschpfadTest extends IntegrationTestCase
{
    public function testLoeschungEntferntKontaktVerlaufUndProtokoll(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['privacy.view', 'privacy.manage'], ['ROLE_ADMIN']);

        $kontakt = $this->kontakt($a, 'Petra', 'Loeschung', '0201 998877');
        $this->verlauf($a, $kontakt, 'Anruf mit Rueckfrage');
        $this->protokoll($kontakt, '0201 998877');

        $antwort = $this->anfrage(
            'POST',
            '/api/privacy/contacts/' . $kontakt->getId() . '/erase',
            $admin,
            ['reason' => 'Betroffener hat die Loeschung verlangt.'],
            'application/json',
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());

        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM contact'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM activity'));
        self::assertSame(
            0,
            (int) $db->fetchOne('SELECT COUNT(*) FROM change_log'),
            'Das Aenderungsprotokoll enthaelt Personendaten und muss mitgeloescht werden (C15).'
        );

        // Die Telefonnummer darf nirgends mehr auffindbar sein.
        $treffer = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM change_log WHERE old_value LIKE '%998877%' OR new_value LIKE '%998877%'"
        );
        self::assertSame(0, $treffer);
    }

    public function testLoeschungOhneGrundWirdAbgelehnt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['privacy.view', 'privacy.manage'], ['ROLE_ADMIN']);
        $kontakt = $this->kontakt($a, 'Petra', 'Bleibt', null);

        $antwort = $this->anfrage(
            'POST',
            '/api/privacy/contacts/' . $kontakt->getId() . '/erase',
            $admin,
            ['reason' => '   '],
            'application/json',
        );

        self::assertSame(422, $antwort->getStatusCode());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testOhneRechtKeineLoeschung(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'sachbearbeiter', ['contacts.view', 'contacts.manage']);
        $kontakt = $this->kontakt($a, 'Petra', 'Bleibt', null);

        $antwort = $this->anfrage(
            'POST',
            '/api/privacy/contacts/' . $kontakt->getId() . '/erase',
            $ohneRecht,
            ['reason' => 'Versuch ohne Recht'],
            'application/json',
        );

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testFremderKontaktLaesstSichNichtLoeschen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $adminB = $this->benutzer($b, 'adminb', ['privacy.view', 'privacy.manage'], ['ROLE_ADMIN']);

        $fremd = $this->kontakt($a, 'Anna', 'Aussen', null);

        $antwort = $this->anfrage(
            'POST',
            '/api/privacy/contacts/' . $fremd->getId() . '/erase',
            $adminB,
            ['reason' => 'Versuch ueber die Mandantengrenze'],
            'application/json',
        );

        self::assertSame(404, $antwort->getStatusCode());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testAuskunftEnthaeltDenVerlauf(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['privacy.view'], ['ROLE_ADMIN']);
        $kontakt = $this->kontakt($a, 'Petra', 'Auskunft', '0201 4711');
        $this->verlauf($a, $kontakt, 'Beratungsgespraech');

        $antwort = $this->anfrage('GET', '/api/privacy/contacts/' . $kontakt->getId() . '/export', $admin);

        self::assertSame(200, $antwort->getStatusCode());
        $inhalt = (string) $antwort->getContent();
        self::assertStringContainsString('Beratungsgespraech', $inhalt);
        self::assertStringContainsString('0201 4711', $inhalt);
    }

    private function kontakt(Tenant $mandant, string $vorname, string $nachname, ?string $telefon): Contact
    {
        $kontakt = (new Contact())
            ->setFirstName($vorname)
            ->setLastName($nachname)
            ->setPhone($telefon)
            ->setSource('formular');
        $kontakt->setTenant($mandant);

        $this->em->persist($kontakt);
        $this->em->flush();

        return $kontakt;
    }

    private function verlauf(Tenant $mandant, Contact $kontakt, string $betreff): void
    {
        $aktivitaet = (new Activity())
            ->setType('notiz')
            ->setSubject($betreff)
            ->setContact($kontakt);
        $aktivitaet->setTenant($mandant);

        $this->em->persist($aktivitaet);
        $this->em->flush();
    }

    private function protokoll(Contact $kontakt, string $wert): void
    {
        $eintrag = new ChangeLog('contact', $kontakt->getId(), 'phone', null, $wert, 'tester');
        $eintrag->setTenant($kontakt->getTenant());

        $this->em->persist($eintrag);
        $this->em->flush();
    }
}
