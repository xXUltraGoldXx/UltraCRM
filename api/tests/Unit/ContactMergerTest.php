<?php

namespace App\Tests\Unit;

use App\Entity\Contact;
use App\Service\ContactMerger;
use PHPUnit\Framework\TestCase;

/**
 * Prueft den echten Merge-Pfad aus ContactMerger, nicht bloss den Zustand,
 * den ein korrekter Merge hinterlassen wuerde. Die Regel, die hier haengt:
 * Zusammenfuehren darf niemanden bewerbbar machen, der es vorher nicht war.
 */
final class ContactMergerTest extends TestCase
{
    private ContactMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new ContactMerger();
    }

    private function kontakt(string $name = 'Ziel'): Contact
    {
        return (new Contact())->setLastName($name);
    }

    /**
     * Der Fall, der in der Abnahme aufgefallen ist: die Quelle hat eine
     * Einwilligung aus einem Formular, deren Bestaetigungslink nie geklickt
     * wurde. Wandert nur consentGivenAt mit, ist das Ziel danach bewerbbar,
     * obwohl niemand bestaetigt hat.
     */
    public function testOffenerBestaetigungslinkWandertMit(): void
    {
        $ziel = $this->kontakt('Bleibt');
        $quelle = $this->kontakt('Bleibt')
            ->setConsentGivenAt(new \DateTimeImmutable('-3 days'))
            ->setConsentText('Newsletter-Formular')
            ->setConfirmToken('nie-geklickt');

        self::assertFalse($ziel->isContactable(), 'Vorbedingung Ziel');
        self::assertFalse($quelle->isContactable(), 'Vorbedingung Quelle');

        $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertSame('nie-geklickt', $ziel->getConfirmToken());
        self::assertFalse($ziel->isContactable(), 'Merge darf das Double-Opt-in nicht ueberspringen.');
    }

    public function testBestaetigteEinwilligungWirdUebernommen(): void
    {
        $ziel = $this->kontakt('Bleibt');
        $quelle = $this->kontakt('Bleibt')
            ->setConsentGivenAt(new \DateTimeImmutable('-10 days'))
            ->setConfirmToken('geklickt')
            ->setConsentConfirmedAt(new \DateTimeImmutable('-9 days'));

        $uebernommen = $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertContains('Einwilligung', $uebernommen);
        self::assertTrue($ziel->isContactable());
    }

    public function testWiderrufDerQuelleGewinnt(): void
    {
        $ziel = $this->kontakt('Bleibt')->setConsentGivenAt(new \DateTimeImmutable('-1 year'));
        $quelle = $this->kontakt('Bleibt')
            ->setConsentGivenAt(new \DateTimeImmutable('-1 year'))
            ->setConsentWithdrawnAt(new \DateTimeImmutable('-1 day'));

        self::assertTrue($ziel->isContactable(), 'Vorbedingung: Ziel war bewerbbar');

        $uebernommen = $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertContains('Widerruf', $uebernommen);
        self::assertFalse($ziel->isContactable());
    }

    /**
     * Bei einer nur moeglichen Dublette koennen es zwei verschiedene Menschen
     * sein — etwa Vater und Sohn im selben Betrieb. Dann darf die Einwilligung
     * des einen nicht auf den anderen uebergehen.
     */
    public function testMoeglicheDubletteUebertraegtKeineEinwilligung(): void
    {
        $ziel = $this->kontakt('Bleibt');
        $quelle = $this->kontakt('Bleibt')
            ->setConsentGivenAt(new \DateTimeImmutable('-5 days'))
            ->setConsentText('Messe');

        $uebernommen = $this->merger->uebernehmen($ziel, $quelle, false);

        self::assertNotContains('Einwilligung', $uebernommen);
        self::assertNull($ziel->getConsentGivenAt());
        self::assertFalse($ziel->isContactable());
    }

    public function testWiderrufWandertAuchBeiMoeglicherDublette(): void
    {
        $ziel = $this->kontakt('Bleibt')->setConsentGivenAt(new \DateTimeImmutable('-1 year'));
        $quelle = $this->kontakt('Bleibt')->setConsentWithdrawnAt(new \DateTimeImmutable('-2 days'));

        $this->merger->uebernehmen($ziel, $quelle, false);

        self::assertFalse($ziel->isContactable(), 'Ein Widerruf faellt immer in die sichere Richtung.');
    }

    public function testNurLeereFelderWerdenGefuellt(): void
    {
        $ziel = $this->kontakt('Bleibt')->setPhone('0201 111')->setPosition('');
        $quelle = $this->kontakt('Weg')->setPhone('0999 999')->setPosition('Einkauf')->setNotes('Notiz');

        $uebernommen = $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertSame('0201 111', $ziel->getPhone(), 'Vorhandenes wird nie ueberschrieben.');
        self::assertSame('Einkauf', $ziel->getPosition(), 'Leerstring gilt als leer.');
        self::assertSame('Notiz', $ziel->getNotes());
        self::assertNotContains('Telefon', $uebernommen);
    }

    public function testLeerstringDerQuelleGiltNichtAlsUebernahme(): void
    {
        $ziel = $this->kontakt('Bleibt');
        $quelle = $this->kontakt('Weg')->setPhone('');

        $uebernommen = $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertNotContains('Telefon', $uebernommen);
        self::assertNull($ziel->getPhone());
    }

    public function testZusatzfelderNurWoLuecken(): void
    {
        $ziel = $this->kontakt('Bleibt')->setCustomData(['kundennr' => 'K-1', 'branche' => null]);
        $quelle = $this->kontakt('Weg')->setCustomData(['kundennr' => 'K-9', 'branche' => 'Handwerk']);

        $this->merger->uebernehmen($ziel, $quelle, true);

        self::assertSame(['kundennr' => 'K-1', 'branche' => 'Handwerk'], $ziel->getCustomData());
    }
}
