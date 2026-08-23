<?php

namespace App\Tests\Unit;

use App\Entity\Deal;
use PHPUnit\Framework\TestCase;

/** Phasenlogik: closedAt und isOpen fuehren sich selbst mit. */
final class DealStageTest extends TestCase
{
    public function testNeuerVorgangIstOffen(): void
    {
        $d = (new Deal())->setTitle('Test');

        self::assertTrue($d->isOpen());
        self::assertNull($d->getClosedAt());
    }

    public function testGewonnenSetztAbschlusszeitpunkt(): void
    {
        $d = (new Deal())->setTitle('Test')->setStage('gewonnen');

        self::assertFalse($d->isOpen());
        self::assertNotNull($d->getClosedAt());
    }

    public function testZurueckholenLeertAbschlusszeitpunkt(): void
    {
        $d = (new Deal())->setTitle('Test')->setStage('gewonnen');
        self::assertNotNull($d->getClosedAt());

        $d->setStage('verhandlung');

        self::assertTrue($d->isOpen());
        self::assertNull($d->getClosedAt(), 'Ein zurueckgeholter Vorgang hat kein Abschlussdatum.');
    }

    public function testAbschlusszeitpunktBleibtBeiPhasenwechselZwischenAbschluessen(): void
    {
        $d = (new Deal())->setTitle('Test')->setStage('gewonnen');
        $erst = $d->getClosedAt();

        $d->setStage('verloren');

        self::assertSame($erst, $d->getClosedAt(), 'Der erste Abschlusszeitpunkt zaehlt.');
    }
}
