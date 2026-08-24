<?php

namespace App\Tests\Integration;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Tenant;

/**
 * Dublettenabgleich beim Import (Alexander, 24.08.): Erkennen, ob ein Kunde
 * schon existiert, und zur Wahl stellen — ergaenzen oder neu anlegen.
 */
final class ImportAbgleichTest extends IntegrationTestCase
{
    // ------------------------------------------------------------ Vorschau

    public function testVorschauFindetBestandskontaktUeberDieEmail(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $this->kontakt($a, 'Marion', 'Hansen', 'm.hansen@test.invalid');

        $antwort = $this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname;E-Mail\nMarion;Hansen;M.Hansen@TEST.invalid\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());
        $inhalt = $this->inhalt($antwort);

        self::assertSame(1, $inhalt['summary']['withMatches']);
        self::assertSame('aktualisieren', $inhalt['rows'][0]['vorschlag']);
        self::assertSame('sicher', $inhalt['rows'][0]['treffer'][0]['sicherheit']);
    }

    /**
     * Alexanders "da auch": gleiche Namen ohne Firma sollen beim Import
     * auffallen — anders als in der regulaeren Dublettenuebersicht, wo sie
     * fast nur Fehltreffer waeren (Analyse.md C20). Hier entscheidet ein
     * Mensch je Zeile, bevor etwas passiert.
     */
    public function testVorschauFindetGleichenNamenOhneFirma(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $this->kontakt($a, 'Michael', 'Schmidt', null);

        $inhalt = $this->inhalt($this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname\nMichael;Schmidt\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName'])],
        ));

        self::assertSame(1, $inhalt['summary']['withMatches']);
        self::assertSame('moeglich', $inhalt['rows'][0]['treffer'][0]['sicherheit']);
    }

    public function testVorschauMeldetNichtsBeiUnbekanntemKontakt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $this->kontakt($a, 'Marion', 'Hansen', 'm.hansen@test.invalid');

        $inhalt = $this->inhalt($this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname;E-Mail\nGanz;Anders;ganz@test.invalid\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        ));

        self::assertSame(0, $inhalt['summary']['withMatches']);
        self::assertSame('neu', $inhalt['rows'][0]['vorschlag']);
    }

    public function testVorschauAendertNichts(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);

        $this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname\nNeuer;Kontakt\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName'])],
        );

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Exportlisten enthalten denselben Menschen oft zweimal. Vor dem
     * Vorschau-Schritt hat die E-Mail-Sperre in execute() das aufgefangen —
     * mit ausdruecklichen Entscheidungen faellt die weg, also muss es hier
     * auffallen.
     */
    public function testGleicheZeileZweimalInDerDateiFaelltAuf(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);

        $inhalt = $this->inhalt($this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname;E-Mail\nMarion;Hansen;m.hansen@test.invalid\nMarion;Hansen;M.Hansen@TEST.invalid\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        ));

        self::assertSame(1, $inhalt['summary']['inFileDuplicates']);
        self::assertNull($inhalt['rows'][0]['dateiDublette'], 'Die erste Zeile ist keine Dublette.');
        self::assertSame('neu', $inhalt['rows'][0]['vorschlag']);
        self::assertSame(2, $inhalt['rows'][1]['dateiDublette'], 'Verweist auf die frühere Zeile.');
        self::assertSame('ueberspringen', $inhalt['rows'][1]['vorschlag']);
    }

    public function testVerschiedeneMenschenInDerDateiSindKeineDublette(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);

        $inhalt = $this->inhalt($this->anfrageMitDatei('/api/import/preview', $nutzer,
            "Vorname;Nachname;E-Mail\nMarion;Hansen;m.hansen@test.invalid\nDieter;Vogt;d.vogt@test.invalid\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        ));

        self::assertSame(0, $inhalt['summary']['inFileDuplicates']);
    }

    // ---------------------------------------------------------- Ergaenzen

    /**
     * Die wichtigste Regel: eine Importdatei ist keine bessere Wahrheit als
     * der gepflegte Datensatz. Nur Luecken werden gefuellt.
     */
    public function testErgaenzenFuelltNurLeereFelder(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $bestand = $this->kontakt($a, 'Marion', 'Hansen', 'm.hansen@test.invalid');
        $bestand->setPhone('0201 111111');
        $this->em->flush();
        $id = $bestand->getId();

        $antwort = $this->anfrageMitDatei('/api/import/execute', $nutzer,
            "Vorname;Nachname;E-Mail;Telefon;Position\nMarion;Hansen;m.hansen@test.invalid;0999 999999;Einkauf\n",
            'import.csv',
            [
                'mapping' => json_encode(['firstName', 'lastName', 'email', 'phone', 'position']),
                'decisions' => json_encode(['2' => ['action' => 'aktualisieren', 'contactId' => $id]]),
            ],
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());
        $inhalt = $this->inhalt($antwort);
        self::assertSame(1, $inhalt['summary']['updated']);
        self::assertSame(0, $inhalt['summary']['imported']);

        $zeile = $this->em->getConnection()
            ->fetchAssociative('SELECT phone, position FROM contact WHERE id = ?', [$id]);

        self::assertSame('0201 111111', $zeile['phone'], 'Gepflegte Nummer darf nicht ueberschrieben werden.');
        self::assertSame('Einkauf', $zeile['position'], 'Leeres Feld wird gefuellt.');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Analyse.md C6/C33: Ein Import belegt keine Einwilligung — auch nicht
     * ueber den Umweg "Bestandskontakt ergaenzen".
     */
    public function testErgaenzenMachtNiemandenBewerbbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $bestand = $this->kontakt($a, 'Marion', 'Hansen', 'm.hansen@test.invalid');
        $id = $bestand->getId();

        $this->anfrageMitDatei('/api/import/execute', $nutzer,
            "Vorname;Nachname;E-Mail;Telefon\nMarion;Hansen;m.hansen@test.invalid;0201 5512340\n",
            'import.csv',
            [
                'mapping' => json_encode(['firstName', 'lastName', 'email', 'phone']),
                'decisions' => json_encode(['2' => ['action' => 'aktualisieren', 'contactId' => $id]]),
            ],
        );

        $zeile = $this->em->getConnection()->fetchAssociative(
            'SELECT consent_given_at, consent_confirmed_at FROM contact WHERE id = ?',
            [$id]
        );

        self::assertNull($zeile['consent_given_at']);
        self::assertNull($zeile['consent_confirmed_at']);
    }

    public function testTrotzTrefferNeuAnlegenIstMoeglich(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $this->kontakt($a, 'Michael', 'Schmidt', 'm.schmidt@test.invalid');

        $antwort = $this->anfrageMitDatei('/api/import/execute', $nutzer,
            "Vorname;Nachname;E-Mail\nMichael;Schmidt;m.schmidt@test.invalid\n",
            'import.csv',
            [
                'mapping' => json_encode(['firstName', 'lastName', 'email']),
                'decisions' => json_encode(['2' => ['action' => 'neu']]),
            ],
        );

        self::assertSame(1, $this->inhalt($antwort)['summary']['imported']);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testUeberspringenLegtNichtsAn(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);

        $antwort = $this->anfrageMitDatei('/api/import/execute', $nutzer,
            "Vorname;Nachname\nEgal;Wer\n",
            'import.csv',
            [
                'mapping' => json_encode(['firstName', 'lastName']),
                'decisions' => json_encode(['2' => ['action' => 'ueberspringen']]),
            ],
        );

        self::assertSame(0, $this->inhalt($antwort)['summary']['imported']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Ohne Entscheidungen muss sich der Import genau wie vorher verhalten —
     * sonst legt ein alter Aufruf ploetzlich Doppelte an.
     */
    public function testOhneEntscheidungenBleibtEsBeimAltenVerhalten(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->importeur($a);
        $this->kontakt($a, 'Marion', 'Hansen', 'm.hansen@test.invalid');

        $antwort = $this->anfrageMitDatei('/api/import/execute', $nutzer,
            "Vorname;Nachname;E-Mail\nMarion;Hansen;m.hansen@test.invalid\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        );

        self::assertSame(1, $this->inhalt($antwort)['summary']['skipped']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    /**
     * Analyse.md C18/C24: der Mandant wird ausdruecklich geprueft, nicht nur
     * ueber den Doctrine-Filter.
     */
    public function testFremderKontaktLaesstSichNichtErgaenzen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->importeur($b, 'b1');
        $fremd = $this->kontakt($a, 'Fremd', 'Person', 'fremd@test.invalid');
        $fremdId = $fremd->getId();

        $antwort = $this->anfrageMitDatei('/api/import/execute', $nutzerB,
            "Vorname;Nachname;Telefon\nFremd;Person;0201 999999\n",
            'import.csv',
            [
                'mapping' => json_encode(['firstName', 'lastName', 'phone']),
                'decisions' => json_encode(['2' => ['action' => 'aktualisieren', 'contactId' => $fremdId]]),
            ],
        );

        self::assertSame(1, $this->inhalt($antwort)['summary']['failed']);
        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT phone FROM contact WHERE id = ?', [$fremdId]),
            'Der fremde Kontakt darf nicht angefasst worden sein.'
        );
    }

    public function testVorschauBrauchtDasRecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'ohne', ['contacts.view']);

        $antwort = $this->anfrageMitDatei('/api/import/preview', $ohneRecht,
            "Vorname;Nachname\nEgal;Wer\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName'])],
        );

        self::assertSame(403, $antwort->getStatusCode());
    }

    // ------------------------------------------------------------- Helfer

    private function importeur(Tenant $mandant, string $name = 'a1'): \App\Entity\User
    {
        return $this->benutzer($mandant, $name, ['contacts.view', 'contacts.manage', 'importexport.use']);
    }

    private function kontakt(Tenant $mandant, string $vorname, string $nachname, ?string $email): Contact
    {
        $kontakt = (new Contact())
            ->setFirstName($vorname)
            ->setLastName($nachname)
            ->setEmail($email)
            ->setSource('telefon');
        $kontakt->setTenant($mandant);

        $this->em->persist($kontakt);
        $this->em->flush();

        return $kontakt;
    }
}
