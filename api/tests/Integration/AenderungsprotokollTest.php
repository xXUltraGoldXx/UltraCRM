<?php

namespace App\Tests\Integration;

use App\Entity\Company;
use App\Entity\Deal;
use App\Entity\Tenant;

/**
 * Das Protokoll soll nicht nur bei Kontakten laufen, sondern auch bei Firmen
 * und Vorgaengen. Geprueft wird ueber die API — der Subscriber haengt am
 * Speichern, nicht am Setzen der Werte.
 */
final class AenderungsprotokollTest extends IntegrationTestCase
{
    public function testAenderungAnFirmaWirdProtokolliert(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'sachbearbeiter', ['contacts.view', 'contacts.manage', 'privacy.view']);
        $firma = $this->firma($a, 'Bäckerei Hansen');

        $antwort = $this->anfrage(
            'PATCH',
            '/api/companies/' . $firma->getId(),
            $nutzer,
            ['name' => 'Bäckerei Hansen GmbH'],
            'application/merge-patch+json',
        );
        self::assertSame(200, $antwort->getStatusCode(), (string) $antwort->getContent());

        $eintrag = $this->em->getConnection()->fetchAssociative(
            'SELECT field, old_value, new_value, changed_by FROM change_log WHERE subject_type = ?',
            ['company']
        );

        self::assertNotFalse($eintrag, 'Zu einer geaenderten Firma muss ein Protokolleintrag entstehen.');
        self::assertSame('name', $eintrag['field']);
        self::assertSame('Bäckerei Hansen', $eintrag['old_value']);
        self::assertSame('Bäckerei Hansen GmbH', $eintrag['new_value']);
        self::assertSame('sachbearbeiter', $eintrag['changed_by']);
    }

    public function testAenderungAmVorgangWirdProtokolliert(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $nutzer = $this->benutzer($a, 'vertrieb', ['deals.view', 'deals.manage', 'privacy.view']);
        $phase = $this->phase($a);

        $vorgang = (new Deal())->setTitle('Wartungsvertrag')->setValue('1000.00');
        $vorgang->setStage($phase);
        $vorgang->setTenant($a);
        $this->em->persist($vorgang);
        $this->em->flush();

        $this->anfrage(
            'PATCH',
            '/api/deals/' . $vorgang->getId(),
            $nutzer,
            ['value' => '2500.00'],
            'application/merge-patch+json',
        );

        $eintrag = $this->em->getConnection()->fetchAssociative(
            "SELECT field, old_value, new_value FROM change_log WHERE subject_type = 'deal' AND field = 'value'"
        );

        self::assertNotFalse($eintrag, 'Zu einem geaenderten Vorgang muss ein Protokolleintrag entstehen.');
        self::assertSame('1000.00', $eintrag['old_value']);
        self::assertSame('2500.00', $eintrag['new_value']);
    }

    /**
     * Das Protokoll enthaelt Personendaten und ist deshalb an das
     * Datenschutzrecht gebunden — nicht an das Recht, den Datensatz zu sehen.
     */
    public function testOhneDatenschutzrechtKeinProtokoll(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneRecht = $this->benutzer($a, 'ohne', ['contacts.view', 'contacts.manage']);
        $firma = $this->firma($a, 'Autohaus Vogt');

        $this->anfrage(
            'PATCH',
            '/api/companies/' . $firma->getId(),
            $ohneRecht,
            ['name' => 'Autohaus Vogt & Söhne'],
            'application/merge-patch+json',
        );

        $antwort = $this->anfrage('GET', '/api/change_logs?subjectType=company', $ohneRecht);

        self::assertSame(403, $antwort->getStatusCode());
    }

    public function testProtokollBleibtImEigenenMandanten(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerA = $this->benutzer($a, 'a1', ['contacts.view', 'contacts.manage', 'privacy.view']);
        $nutzerB = $this->benutzer($b, 'b1', ['contacts.view', 'privacy.view']);

        $firma = $this->firma($a, 'Metallbau Sikorski');
        $this->anfrage(
            'PATCH',
            '/api/companies/' . $firma->getId(),
            $nutzerA,
            ['city' => 'Recklinghausen'],
            'application/merge-patch+json',
        );

        $eigene = $this->inhalt($this->anfrage('GET', '/api/change_logs?subjectType=company', $nutzerA));
        $fremde = $this->inhalt($this->anfrage('GET', '/api/change_logs?subjectType=company', $nutzerB));

        self::assertGreaterThan(0, $eigene['totalItems']);
        self::assertSame(0, $fremde['totalItems'], 'Mandant B darf das Protokoll von A nicht sehen.');
    }

    private function firma(Tenant $mandant, string $name): Company
    {
        $firma = (new Company())->setName($name);
        $firma->setTenant($mandant);

        $this->em->persist($firma);
        $this->em->flush();

        return $firma;
    }
}
