<?php

namespace App\Tests\Integration;

use App\Entity\Contact;
use App\Entity\Tenant;

/**
 * Export und Import wurden seinerzeit an einen Agenten delegiert, der die
 * Mandantentrennung nicht geprueft hat — bei einer Funktion, die alle Daten
 * auf einmal aus dem System traegt, ist das die wichtigste Frage ueberhaupt.
 * Der Importweg lief bisher ebenfalls durch keinen Test von Anfang bis Ende.
 */
final class ExportImportTest extends IntegrationTestCase
{
    // ------------------------------------------------------------- Export

    public function testExportEnthaeltNurEigeneKontakte(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerA = $this->benutzer($a, 'a1', ['contacts.view', 'importexport.use']);

        $this->kontakt($a, 'Anna', 'Eigen');
        $this->kontakt($b, 'Bert', 'Fremd');

        $antwort = $this->anfrage('GET', '/api/export/contacts.csv', $nutzerA);
        $inhalt = $this->streamInhalt($antwort);

        self::assertSame(200, $antwort->getStatusCode());
        self::assertStringContainsString('Eigen', $inhalt);
        self::assertStringNotContainsString(
            'Fremd',
            $inhalt,
            'Ein Export darf niemals Daten eines anderen Mandanten enthalten.'
        );
    }

    public function testExportBrauchtDasRecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'nur_lesen', ['contacts.view']);

        self::assertSame(403, $this->anfrage('GET', '/api/export/contacts.csv', $ohneRecht)->getStatusCode());
    }

    /**
     * Excel in DACH braucht das BOM, sonst zerfallen die Umlaute, und das
     * Semikolon, sonst landet alles in einer Spalte (Entscheidung aus A11).
     */
    public function testCsvIstFuerDeutschesExcelBrauchbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'a1', ['contacts.view', 'importexport.use']);
        $this->kontakt($a, 'Jürgen', 'Müller-Lüdenscheidt');

        $inhalt = $this->streamInhalt($this->anfrage('GET', '/api/export/contacts.csv', $nutzer));

        self::assertStringStartsWith("\xEF\xBB\xBF", $inhalt, 'CSV braucht ein BOM.');
        self::assertStringContainsString(';', $inhalt, 'Getrennt wird mit Semikolon.');
        self::assertStringContainsString('Müller-Lüdenscheidt', $inhalt);
    }

    // ------------------------------------------------------------- Import

    public function testImportErkenntDieSpaltenUndLegtKontakteAn(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'a1', ['contacts.view', 'contacts.manage', 'importexport.use']);

        $csv = "Vorname;Nachname;E-Mail;Telefon\n"
            . "Marion;Hansen;m.hansen@test.invalid;0201 5512340\n"
            . "Dieter;Vogt;d.vogt@test.invalid;0209 776510\n";

        $analyse = $this->inhalt($this->anfrageMitDatei('/api/import/analyze', $nutzer, $csv));
        self::assertSame(['Vorname', 'Nachname', 'E-Mail', 'Telefon'], $analyse['headers']);

        $antwort = $this->anfrageMitDatei(
            '/api/import/execute',
            $nutzer,
            $csv,
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email', 'phone'])],
        );

        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());

        $db = $this->em->getConnection();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM contact'));
        self::assertSame(
            '0201 5512340',
            $db->fetchOne('SELECT phone FROM contact WHERE last_name = ?', ['Hansen'])
        );
    }

    /**
     * Analyse.md C6: Importierte Kontakte haben keine nachweisbare
     * Einwilligung. Sie duerfen nicht als bewerbbar gelten.
     */
    public function testImportierteKontakteSindNichtBewerbbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'a1', ['contacts.view', 'contacts.manage', 'importexport.use']);

        $csv = "Vorname;Nachname;E-Mail\nLena;Lead;lena@test.invalid\n";

        $this->anfrageMitDatei(
            '/api/import/execute',
            $nutzer,
            $csv,
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName', 'email'])],
        );

        $zeile = $this->em->getConnection()
            ->fetchAssociative('SELECT consent_given_at, source FROM contact WHERE last_name = ?', ['Lead']);

        self::assertNotFalse($zeile);
        self::assertNull($zeile['consent_given_at'], 'Ein Import belegt keine Einwilligung.');
    }

    public function testImportLandetImEigenenMandanten(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->benutzer($b, 'b1', ['contacts.view', 'contacts.manage', 'importexport.use']);

        $this->anfrageMitDatei(
            '/api/import/execute',
            $nutzerB,
            "Vorname;Nachname\nNeu;Zugang\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName'])],
        );

        $mandantId = $this->em->getConnection()
            ->fetchOne('SELECT tenant_id FROM contact WHERE last_name = ?', ['Zugang']);

        self::assertSame($b->getId(), (int) $mandantId);
        self::assertNotSame($a->getId(), (int) $mandantId);
    }

    public function testImportBrauchtDasRecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'nur_lesen', ['contacts.view', 'contacts.manage']);

        $antwort = $this->anfrageMitDatei(
            '/api/import/execute',
            $ohneRecht,
            "Vorname;Nachname\nKein;Zugang\n",
            'import.csv',
            ['mapping' => json_encode(['firstName', 'lastName'])],
        );

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testImportOhneZuordnungWirdAbgelehnt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'a1', ['contacts.view', 'contacts.manage', 'importexport.use']);

        $antwort = $this->anfrageMitDatei(
            '/api/import/execute',
            $nutzer,
            "Vorname;Nachname\nOhne;Zuordnung\n",
            'import.csv',
        );

        self::assertSame(422, $antwort->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    // ------------------------------------------------------------- Helfer

    private function kontakt(Tenant $mandant, string $vorname, string $nachname): Contact
    {
        $kontakt = (new Contact())
            ->setFirstName($vorname)
            ->setLastName($nachname)
            ->setSource('telefon');
        $kontakt->setTenant($mandant);

        $this->em->persist($kontakt);
        $this->em->flush();

        return $kontakt;
    }

    /** Der Export wird gestreamt — der Inhalt entsteht erst beim Senden. */
    private function streamInhalt(\Symfony\Component\HttpFoundation\Response $antwort): string
    {
        ob_start();
        $antwort->sendContent();

        return (string) ob_get_clean();
    }
}
