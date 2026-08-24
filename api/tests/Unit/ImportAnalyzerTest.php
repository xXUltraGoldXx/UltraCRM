<?php

namespace App\Tests\Unit;

use App\Service\ImportAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Die Spaltenerkennung war in der Entwicklung zweimal falsch (Analyse.md
 * C8 und C9): erst erkannte sie zu wenig, dann nach dem Fix zu viel und
 * ordnete "Firmenname" dem Nachnamen zu. Beide Richtungen stehen hier als
 * Test — besonders die Faelle, die NICHT treffen duerfen.
 */
final class ImportAnalyzerTest extends TestCase
{
    private function analyzer(): ImportAnalyzer
    {
        return new ImportAnalyzer();
    }

    /** @return array<string, string> Spalte => vorgeschlagenes Feld */
    private function zuordnung(array $spalten): array
    {
        return array_combine($spalten, $this->analyzer()->suggestMapping($spalten));
    }

    public function testDeutscheSpaltennamen(): void
    {
        $z = $this->zuordnung(['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Firma', 'Position', 'Abteilung']);

        self::assertSame([
            'Vorname' => 'firstName',
            'Nachname' => 'lastName',
            'E-Mail' => 'email',
            'Telefon' => 'phone',
            'Firma' => 'company',
            'Position' => 'position',
            'Abteilung' => 'department',
        ], $z);
    }

    public function testEnglischeSpaltennamen(): void
    {
        $z = $this->zuordnung(['First Name', 'Last Name', 'Email', 'Phone', 'Company', 'Job Title']);

        self::assertSame('firstName', $z['First Name']);
        self::assertSame('lastName', $z['Last Name']);
        self::assertSame('email', $z['Email']);
        self::assertSame('company', $z['Company']);
        self::assertSame('position', $z['Job Title']);
    }

    /** C8: "Mail-Adresse" wurde frueher gar nicht erkannt. */
    public function testZusammengesetzteNamenWerdenErkannt(): void
    {
        $z = $this->zuordnung(['Mail-Adresse', 'Firmenname', 'Given Name', 'Familienname']);

        self::assertSame('email', $z['Mail-Adresse']);
        self::assertSame('company', $z['Firmenname']);
        self::assertSame('firstName', $z['Given Name']);
        self::assertSame('lastName', $z['Familienname']);
    }

    /**
     * C9: der Fix fuer C8 ordnete zunaechst FALSCH zu. Eine falsche
     * Zuordnung ist schaedlicher als gar keine — dann landen Firmennamen im
     * Nachnamensfeld. Diese Spalten muessen ignoriert werden.
     */
    public function testStoerspaltenWerdenIgnoriert(): void
    {
        $z = $this->zuordnung([
            'Kundennummer', 'Artikelnummer', 'Rechnungsnummer',
            'Firmenwagen', 'Titel des Projekts', 'Bemerkung', 'Umsatz 2025',
        ]);

        foreach ($z as $spalte => $feld) {
            self::assertSame(
                ImportAnalyzer::IGNORE,
                $feld,
                sprintf('Spalte "%s" darf keinem Feld zugeordnet werden, wurde aber "%s".', $spalte, $feld)
            );
        }
    }

    public function testGrossKleinUndSonderzeichenEgal(): void
    {
        $z = $this->zuordnung(['VORNAME', 'nach name', 'E_Mail', 'firmA']);

        self::assertSame('firstName', $z['VORNAME']);
        self::assertSame('lastName', $z['nach name']);
        self::assertSame('email', $z['E_Mail']);
        self::assertSame('company', $z['firmA']);
    }

    public function testUnbekannteSpalteBleibtIgnoriert(): void
    {
        $z = $this->zuordnung(['Lieblingsfarbe', 'Schuhgröße']);

        self::assertSame(ImportAnalyzer::IGNORE, $z['Lieblingsfarbe']);
        self::assertSame(ImportAnalyzer::IGNORE, $z['Schuhgröße']);
    }
}
