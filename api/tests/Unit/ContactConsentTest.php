<?php

namespace App\Tests\Unit;

use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

/**
 * Die Einwilligungslogik ist das Herz des DSGVO-Versprechens — und genau
 * hier ist in der Entwicklung ein Fehler passiert (Analyse.md C6): ein
 * fehlgeschlagener Mailversand loeschte den Token und machte den Kontakt
 * dadurch sofort "kontaktierbar". Diese Tests halten die Regeln fest.
 */
final class ContactConsentTest extends TestCase
{
    public function testOhneEinwilligungNichtKontaktierbar(): void
    {
        $k = (new Contact())->setLastName('Test');

        self::assertFalse($k->isContactable(), 'Ohne Einwilligung darf nicht geworben werden.');
    }

    public function testMitEinwilligungOhneBestaetigungslinkKontaktierbar(): void
    {
        // Fall Messe: auf Papier unterschrieben, es wurde nie eine
        // Bestaetigungsmail verschickt.
        $k = (new Contact())->setLastName('Messe')->setConsentGivenAt(new \DateTimeImmutable());

        self::assertTrue($k->isContactable());
    }

    public function testOffenerBestaetigungslinkSperrt(): void
    {
        $k = (new Contact())
            ->setLastName('Formular')
            ->setConsentGivenAt(new \DateTimeImmutable())
            ->setConfirmToken('abc123');

        self::assertFalse(
            $k->isContactable(),
            'Solange die Bestaetigung aussteht, darf nicht geworben werden.'
        );
        self::assertTrue($k->isAwaitingConfirmation());
    }

    public function testNachBestaetigungKontaktierbar(): void
    {
        $k = (new Contact())
            ->setLastName('Formular')
            ->setConsentGivenAt(new \DateTimeImmutable())
            ->setConfirmToken('abc123')
            ->setConsentConfirmedAt(new \DateTimeImmutable());

        self::assertTrue($k->isContactable());
    }

    public function testWiderrufSperrtSofort(): void
    {
        $k = (new Contact())
            ->setLastName('Widerruf')
            ->setConsentGivenAt(new \DateTimeImmutable('-1 year'))
            ->setConsentWithdrawnAt(new \DateTimeImmutable());

        self::assertFalse($k->isContactable(), 'Nach Widerruf ist Schluss.');
    }

    /**
     * Der eigentliche Regressionstest zu C6: Ein Kontakt mit offenem Token
     * darf NICHT dadurch kontaktierbar werden, dass irgendwo im Fehlerpfad
     * der Token entfernt wird, ohne dass eine Bestaetigung vorliegt.
     */
    public function testTokenEntfernenOhneBestaetigungIstEinFehler(): void
    {
        $k = (new Contact())
            ->setLastName('Fehlerpfad')
            ->setConsentGivenAt(new \DateTimeImmutable())
            ->setConfirmToken('abc123');

        self::assertFalse($k->isContactable());

        // Genau das tat der fehlerhafte Code:
        $k->setConfirmToken(null);

        self::assertTrue(
            $k->isContactable(),
            'Dokumentiert das Verhalten: ohne Token gilt die Einwilligung als ausreichend. '
            . 'Deshalb darf der Token im Fehlerfall NIE entfernt werden — siehe PublicLeadController.'
        );
    }
}
