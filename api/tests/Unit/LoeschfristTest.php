<?php

namespace App\Tests\Unit;

use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

/**
 * "30 Tage, wenn nichts anderes eingestellt wird" (Alexander, 24.08.). Nur
 * ein Vorschlag fuer die Pruefliste (/privacy/due-deletions), keine
 * automatische Loeschung — die bleibt eine bewusste Handlung ueber /erase.
 */
final class LoeschfristTest extends TestCase
{
    public function testNeuerKontaktBekommtDreissigTageStandard(): void
    {
        $vorher = new \DateTimeImmutable('+29 days');
        $kontakt = (new Contact())->setLastName('Test');
        $nachher = new \DateTimeImmutable('+31 days');

        self::assertNotNull($kontakt->getDeleteAfter());
        self::assertGreaterThan($vorher, $kontakt->getDeleteAfter());
        self::assertLessThan($nachher, $kontakt->getDeleteAfter());
    }

    public function testEigenerWertUeberschreibtDenStandard(): void
    {
        $eigen = new \DateTimeImmutable('2030-01-01');
        $kontakt = (new Contact())->setLastName('Test')->setDeleteAfter($eigen);

        self::assertSame($eigen, $kontakt->getDeleteAfter());
    }

    public function testLaesstSichAuchWiederLeeren(): void
    {
        // "Fuer diesen Kontakt keine Loeschvormerkung" muss moeglich bleiben,
        // ohne dass sie beim naechsten Speichern zurueckkommt — der Standard
        // wirkt nur im Konstruktor, nicht bei jedem Aufruf.
        $kontakt = (new Contact())->setLastName('Test')->setDeleteAfter(null);

        self::assertNull($kontakt->getDeleteAfter());
    }
}
