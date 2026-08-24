<?php

namespace App\Tests\Integration;

use App\Entity\Contact;
use App\Entity\PermissionGroup;
use App\Entity\Tenant;
use App\Service\Standardgruppen;

/**
 * A14 Schritt 2 und 3: Vorlagen und "Löschen" als eigenes Recht je Bereich.
 *
 * Der Kern: Löschen hing bisher hart am Adminrecht. Jetzt lässt es sich
 * gezielt vergeben — ohne dass jemand gleich Administrator wird.
 */
final class GruppenRechteTest extends IntegrationTestCase
{
    public function testLoeschenOhneAdminrechtMitPassenderGruppe(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $gruppe = $this->gruppe($a, 'Darf löschen', [
            'contacts' => ['lesen' => true, 'schreiben' => true, 'loeschen' => true],
        ]);
        $nutzer = $this->benutzer($a, 'loescher', []);
        $nutzer->setPermissionGroup($gruppe);
        $this->em->flush();

        $kontakt = $this->kontakt($a, 'Weg', 'Damit');

        $antwort = $this->anfrage('DELETE', '/api/contacts/' . $kontakt->getId(), $nutzer);

        self::assertSame(204, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Die Trennung, um die es Alexander ging: anlegen ja, löschen nein.
     */
    public function testSchreibenAlleinDarfNichtLoeschen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $gruppe = $this->gruppe($a, 'Praktikant', [
            'contacts' => ['lesen' => true, 'schreiben' => true],
        ]);
        $nutzer = $this->benutzer($a, 'praktikant', []);
        $nutzer->setPermissionGroup($gruppe);
        $this->em->flush();

        $kontakt = $this->kontakt($a, 'Bleibt', 'Stehen');

        $antwort = $this->anfrage('DELETE', '/api/contacts/' . $kontakt->getId(), $nutzer);

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Löschen im einen Bereich erlaubt nicht das Löschen im anderen — sonst
     * wäre "pro Bereich einstellbar" nur behauptet.
     */
    public function testLoeschrechtGiltNurImEigenenBereich(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $gruppe = $this->gruppe($a, 'Nur Kontakte löschen', [
            'contacts' => ['lesen' => true, 'schreiben' => true, 'loeschen' => true],
            'deals' => ['lesen' => true, 'schreiben' => true],
        ]);
        $nutzer = $this->benutzer($a, 'teilweise', []);
        $nutzer->setPermissionGroup($gruppe);
        $this->em->flush();

        $vorgang = (new \App\Entity\Deal())->setTitle('Bleibt');
        $vorgang->setStage($this->phase($a));
        $vorgang->setTenant($a);
        $this->em->persist($vorgang);
        $this->em->flush();

        $antwort = $this->anfrage('DELETE', '/api/deals/' . $vorgang->getId(), $nutzer);

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM deal'));
    }

    /**
     * Die endgültige Löschung nach Art. 17 vergibt keine Vorlage. Wer
     * Datenschutz lesen und bearbeiten darf, darf noch lange nicht löschen.
     */
    public function testDsgvoLoeschungBrauchtDenEigenenSchalter(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $gruppe = $this->gruppe($a, 'Datenschutz ohne Löschen', [
            'privacy' => ['lesen' => true, 'schreiben' => true],
        ]);
        $nutzer = $this->benutzer($a, 'datenschutz', []);
        $nutzer->setPermissionGroup($gruppe);
        $this->em->flush();

        $kontakt = $this->kontakt($a, 'Bleibt', 'Erhalten');

        $antwort = $this->anfrage(
            'POST',
            '/api/privacy/contacts/' . $kontakt->getId() . '/erase',
            $nutzer,
            ['reason' => 'Versuch ohne Schalter'],
            'application/json',
        );

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testAdminLoeschtWeiterhinOhneGruppe(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', [], ['ROLE_ADMIN']);
        $kontakt = $this->kontakt($a, 'Weg', 'Damit');

        self::assertSame(204, $this->anfrage('DELETE', '/api/contacts/' . $kontakt->getId(), $admin)->getStatusCode());
    }

    // ------------------------------------------------------------ Vorlagen

    public function testNeuerMandantBekommtDieVierVorlagen(): void
    {
        $a = $this->mandant('Frischer Mandant', 'frisch');

        $namen = array_map(
            static fn (PermissionGroup $g) => $g->getName(),
            $this->em->getRepository(PermissionGroup::class)->findBy(['tenant' => $a])
        );

        self::assertCount(4, $namen);
        self::assertContains('Nur Lesen', $namen);
        self::assertContains('Voller Zugriff (kein Admin)', $namen);
    }

    public function testVollzugriffVergibtKeineDsgvoLoeschung(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $voll = $this->em->getRepository(PermissionGroup::class)
            ->findOneBy(['tenant' => $a, 'name' => 'Voller Zugriff (kein Admin)']);

        self::assertNotNull($voll);
        $schluessel = $voll->alsRechteSchluessel();

        self::assertContains('contacts.delete', $schluessel, 'Im Tagesgeschäft darf gelöscht werden.');
        self::assertNotContains('privacy.delete', $schluessel, 'Die Art.-17-Löschung nicht.');
    }

    public function testVorlagenLassenSichNichtDoppeltAnlegen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $dienst = self::getContainer()->get(Standardgruppen::class);

        $nochmal = $dienst->anlegen($a);
        $this->em->flush();

        self::assertSame([], $nochmal, 'Ein zweiter Lauf darf nichts anlegen.');
        self::assertSame(
            4,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permission_group')
        );
    }

    // ------------------------------------------------------------- Helfer

    private function gruppe(Tenant $mandant, string $name, array $rechte): PermissionGroup
    {
        $gruppe = (new PermissionGroup())->setName($name)->setRechte($rechte);
        $gruppe->setTenant($mandant);
        $this->em->persist($gruppe);
        $this->em->flush();

        return $gruppe;
    }

    private function kontakt(Tenant $mandant, string $vorname, string $nachname): Contact
    {
        $kontakt = (new Contact())->setFirstName($vorname)->setLastName($nachname)->setSource('telefon');
        $kontakt->setTenant($mandant);
        $this->em->persist($kontakt);
        $this->em->flush();

        return $kontakt;
    }
}
