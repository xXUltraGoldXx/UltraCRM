<?php

namespace App\Tests\Unit;

use App\Entity\CustomFieldDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/** Die Regeln der Feld-Definition selbst. */
final class CustomFieldDefinitionTest extends TestCase
{
    private function pruefen(CustomFieldDefinition $d): array
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $meldungen = [];
        foreach ($validator->validate($d) as $verstoss) {
            $meldungen[] = $verstoss->getMessage();
        }

        return $meldungen;
    }

    public function testAuswahlBrauchtMindestensZweiMoeglichkeiten(): void
    {
        $d = (new CustomFieldDefinition())
            ->setFieldKey('branche')
            ->setLabel('Branche')
            ->setType('auswahl')
            ->setOptions(['nur eine']);

        self::assertContains('Eine Auswahl braucht mindestens zwei Möglichkeiten.', $this->pruefen($d));
    }

    public function testAuswahlMitZweiMoeglichkeitenIstInOrdnung(): void
    {
        $d = (new CustomFieldDefinition())
            ->setFieldKey('branche')
            ->setLabel('Branche')
            ->setType('auswahl')
            ->setOptions(['Handel', 'Handwerk']);

        self::assertSame([], $this->pruefen($d));
    }

    public function testSchluesselMussTechnischSein(): void
    {
        $d = (new CustomFieldDefinition())
            ->setFieldKey('Falsch-Key')
            ->setLabel('Test')
            ->setType('text');

        $meldungen = $this->pruefen($d);
        self::assertNotEmpty($meldungen, 'Grossbuchstaben und Bindestriche duerfen nicht durchgehen.');
    }

    public function testGueltigerSchluessel(): void
    {
        $d = (new CustomFieldDefinition())
            ->setFieldKey('kundennummer_2')
            ->setLabel('Kundennummer')
            ->setType('text');

        self::assertSame([], $this->pruefen($d));
    }

    public function testUnbekannterTypWirdAbgelehnt(): void
    {
        $d = (new CustomFieldDefinition())
            ->setFieldKey('feld')
            ->setLabel('Feld')
            ->setType('regenbogen');

        self::assertContains('Unbekannter Feldtyp.', $this->pruefen($d));
    }
}
