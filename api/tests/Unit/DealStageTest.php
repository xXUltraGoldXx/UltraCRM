<?php

namespace App\Tests\Unit;

use App\Entity\Deal;
use App\Entity\Stage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Seit A5 entscheidet nicht mehr der Name einer Phase darueber, ob ein
 * Vorgang abgeschlossen ist, sondern ihre Art. Ein Mandant, der seine
 * Endphase "Auftrag erteilt" nennt, muss trotzdem einen gewonnenen Vorgang
 * bekommen.
 */
final class DealStageTest extends TestCase
{
    private function phase(string $name, string $art): Stage
    {
        return (new Stage())->setName($name)->setArt($art);
    }

    public function testEigenerNameZaehltTrotzdemAlsGewonnen(): void
    {
        $deal = (new Deal())->setTitle('Wartungsvertrag');
        $deal->setStage($this->phase('Auftrag erteilt', Stage::GEWONNEN));

        self::assertFalse($deal->isOpen());
        self::assertNotNull($deal->getClosedAt(), 'Abschlusszeitpunkt wird mitgefuehrt.');
        self::assertSame('Auftrag erteilt', $deal->getStageName());
    }

    public function testOffenePhaseBleibtOffen(): void
    {
        $deal = (new Deal())->setTitle('Angebot');
        $deal->setStage($this->phase('In Klaerung', Stage::OFFEN));

        self::assertTrue($deal->isOpen());
        self::assertNull($deal->getClosedAt());
    }

    /**
     * Wird ein bereits abgeschlossener Vorgang zurueck in eine offene Phase
     * gezogen, darf der alte Abschlusszeitpunkt nicht stehen bleiben — sonst
     * zaehlt die Auswertung ihn weiter als Abschluss.
     */
    public function testZurueckInOffenePhaseLoeschtDenAbschluss(): void
    {
        $deal = (new Deal())->setTitle('Doch nicht');
        $deal->setStage($this->phase('Gewonnen', Stage::GEWONNEN));
        self::assertNotNull($deal->getClosedAt());

        $deal->setStage($this->phase('Verhandlung', Stage::OFFEN));

        self::assertNull($deal->getClosedAt());
        self::assertTrue($deal->isOpen());
    }

    /**
     * Wechselt ein Vorgang von "gewonnen" nach "verloren", bleibt der erste
     * Abschlusszeitpunkt stehen — er sagt, wann der Vorgang aus dem offenen
     * Geschaeft ausgeschieden ist, nicht wann zuletzt jemand die Phase
     * korrigiert hat. (Test stammt aus der Fassung vor A5 und gilt weiter.)
     */
    public function testAbschlusszeitpunktBleibtBeiWechselZwischenAbschluessen(): void
    {
        $deal = (new Deal())->setTitle('Test');
        $deal->setStage($this->phase('Gewonnen', Stage::GEWONNEN));
        $erst = $deal->getClosedAt();

        $deal->setStage($this->phase('Verloren', Stage::VERLOREN));

        self::assertSame($erst, $deal->getClosedAt(), 'Der erste Abschlusszeitpunkt zaehlt.');
    }

    public function testOhnePhaseGiltAlsOffen(): void
    {
        $deal = (new Deal())->setTitle('Frisch');

        self::assertTrue($deal->isOpen(), 'Ein unvollstaendiger Datensatz darf nicht als abgeschlossen zaehlen.');
        self::assertNull($deal->getStageName());
    }

    public function testVerlorenVerlangtEinenGrund(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $deal = (new Deal())->setTitle('Verloren ohne Grund');
        $deal->setStage($this->phase('Kein Interesse', Stage::VERLOREN));

        $fehler = $validator->validate($deal);
        $meldungen = array_map(static fn ($f) => $f->getMessage(), iterator_to_array($fehler));

        self::assertContains('Bitte kurz angeben, warum der Vorgang verloren ging.', $meldungen);

        $deal->setLostReason('Preis zu hoch');
        $fehler = $validator->validate($deal);
        $meldungen = array_map(static fn ($f) => $f->getMessage(), iterator_to_array($fehler));

        self::assertNotContains('Bitte kurz angeben, warum der Vorgang verloren ging.', $meldungen);
    }

    /**
     * Ein unbekannter Wert in `art` darf einen Vorgang nicht stillschweigend
     * aus dem offenen Geschaeft entfernen. Er zaehlt dann weder als gewonnen
     * noch als verloren — er waere schlicht verschwunden.
     */
    public function testUnbekannteArtGiltAlsOffen(): void
    {
        $deal = (new Deal())->setTitle('Kaputte Phase');
        $deal->setStage($this->phase('Irgendwas', 'unbekannter_wert'));

        self::assertTrue($deal->isOpen(), 'Unbekannte Art faellt in die sichere Richtung.');
        self::assertNull($deal->getClosedAt());
    }

    public function testArtDerPhaseIstBegrenzt(): void
    {
        self::assertSame(['offen', 'gewonnen', 'verloren'], Stage::ARTEN);
        self::assertTrue($this->phase('Neu', Stage::OFFEN)->istOffen());
        self::assertFalse($this->phase('Gewonnen', Stage::GEWONNEN)->istOffen());
    }
}
