<?php

namespace App\Tests\Integration;

use App\Entity\Contact;
use App\Entity\Deal;

/**
 * Die Mandantentrennung ist das Versprechen, mit dem dieses CRM steht und
 * faellt. Bisher wurde sie nach jeder Aenderung von Hand ueber die API
 * geprueft — hier laeuft sie automatisch.
 */
final class MandantentrennungTest extends IntegrationTestCase
{
    public function testListenZeigenNurEigeneKontakte(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerA = $this->benutzer($a, 'a1', ['contacts.view']);
        $nutzerB = $this->benutzer($b, 'b1', ['contacts.view']);

        $this->kontakt($a, 'Anna', 'Aussen');
        $this->kontakt($b, 'Bert', 'Binnen');

        $antwortA = $this->inhalt($this->anfrage('GET', '/api/contacts', $nutzerA));
        $antwortB = $this->inhalt($this->anfrage('GET', '/api/contacts', $nutzerB));

        self::assertSame(1, $antwortA['totalItems']);
        self::assertSame('Anna', $antwortA['member'][0]['firstName']);
        self::assertSame(1, $antwortB['totalItems']);
        self::assertSame('Bert', $antwortB['member'][0]['firstName']);
    }

    public function testFremderKontaktIstNichtLesbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->benutzer($b, 'b1', ['contacts.view', 'contacts.manage']);

        $fremd = $this->kontakt($a, 'Anna', 'Aussen');

        self::assertSame(404, $this->anfrage('GET', '/api/contacts/' . $fremd->getId(), $nutzerB)->getStatusCode());
    }

    public function testFremderKontaktIstNichtAenderbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->benutzer($b, 'b1', ['contacts.view', 'contacts.manage']);

        $fremd = $this->kontakt($a, 'Anna', 'Aussen');

        $antwort = $this->anfrage(
            'PATCH',
            '/api/contacts/' . $fremd->getId(),
            $nutzerB,
            ['lastName' => 'Gekapert'],
            'application/merge-patch+json',
        );

        self::assertSame(404, $antwort->getStatusCode());

        // Bewusst an Doctrine vorbei: nach der Anfrage steht der
        // Mandantenfilter auf Mandant B, ein find() waere hier immer null —
        // und der Test wuerde bestehen, selbst wenn der Datensatz geaendert
        // worden waere.
        $nachname = $this->em->getConnection()
            ->fetchOne('SELECT last_name FROM contact WHERE id = ?', [$fremd->getId()]);
        self::assertSame('Aussen', $nachname);
    }

    /**
     * Der Fall aus Analyse.md C24: ein Vorgang darf nicht an einer Phase
     * eines fremden Mandanten haengen.
     */
    public function testVorgangKannNichtInFremdePhase(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->benutzer($b, 'b1', ['deals.view', 'deals.manage']);

        $fremdePhase = $this->phase($a);

        $antwort = $this->anfrage('POST', '/api/deals', $nutzerB, [
            'title' => 'Einschleusversuch',
            'stage' => '/api/stages/' . $fremdePhase->getId(),
        ]);

        self::assertContains($antwort->getStatusCode(), [400, 404, 422], sprintf(
            'Erwartet wurde eine Ablehnung, bekommen: %d',
            $antwort->getStatusCode()
        ));
        self::assertSame(0, $this->em->getRepository(Deal::class)->count([]));
    }

    public function testEigenePhaseFunktioniertWeiterhin(): void
    {
        $b = $this->mandant('Mandant B', 'b');
        $nutzerB = $this->benutzer($b, 'b1', ['deals.view', 'deals.manage']);

        $eigenePhase = $this->phase($b);

        $antwort = $this->anfrage('POST', '/api/deals', $nutzerB, [
            'title' => 'Regulaerer Vorgang',
            'stage' => '/api/stages/' . $eigenePhase->getId(),
        ]);

        self::assertSame(201, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertSame(1, $this->em->getRepository(Deal::class)->count([]));
    }

    private function kontakt(\App\Entity\Tenant $mandant, string $vorname, string $nachname): Contact
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
}
