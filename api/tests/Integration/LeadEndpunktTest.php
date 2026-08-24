<?php

namespace App\Tests\Integration;

use App\Entity\Contact;
use App\Entity\LeadForm;
use App\Entity\MailSetting;
use App\Entity\Tenant;

/**
 * Der Lead-Endpunkt ist die einzige Stelle, die ohne Anmeldung erreichbar
 * ist. Entsprechend viel haengt an ihm: Mandantenzuordnung ueber den Token,
 * Einwilligung als Pflicht, Double-Opt-in, Honeypot und Rate-Limit.
 */
final class LeadEndpunktTest extends IntegrationTestCase
{
    public function testEinsendungLandetImRichtigenMandanten(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $b = $this->mandant('Mandant B', 'b');
        $formularB = $this->formular($b);
        $this->formular($a);

        $antwort = $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formularB->getToken(),
            'firstName' => 'Lena',
            'lastName' => 'Lead',
            'email' => 'lena@test.invalid',
            'consent' => true,
        ], 'application/json');

        self::assertSame(202, $antwort->getStatusCode(), (string) $antwort->getContent());

        $mandantId = $this->em->getConnection()
            ->fetchOne('SELECT tenant_id FROM contact WHERE last_name = ?', ['Lead']);
        self::assertSame($b->getId(), (int) $mandantId, 'Der Token bestimmt den Mandanten.');
    }

    /**
     * Analyse.md C6: Wer den Bestaetigungslink nicht geklickt hat, darf nicht
     * bewerbbar sein — auch dann nicht, wenn beim Versand etwas schiefgeht.
     */
    public function testNeuerLeadIstNochNichtBewerbbar(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $formular = $this->formular($a);

        $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formular->getToken(),
            'lastName' => 'Ungeklickt',
            'email' => 'u@test.invalid',
            'consent' => true,
        ], 'application/json');

        $zeile = $this->em->getConnection()->fetchAssociative(
            'SELECT confirm_token, consent_confirmed_at FROM contact WHERE last_name = ?',
            ['Ungeklickt']
        );

        self::assertNotEmpty($zeile['confirm_token'] ?? null, 'Es muss ein Bestaetigungstoken geben.');
        self::assertNull($zeile['consent_confirmed_at'] ?? null);
    }

    public function testOhneEinwilligungKeinKontakt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $formular = $this->formular($a);

        $antwort = $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formular->getToken(),
            'lastName' => 'OhneHaken',
            'consent' => false,
        ], 'application/json');

        self::assertSame(422, $antwort->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Contact::class)->count([]));
    }

    public function testUnbekannterTokenLegtNichtsAn(): void
    {
        $this->mandant('Mandant A', 'a');

        $antwort = $this->anfrage('POST', '/api/public/leads', null, [
            'token' => 'gibt-es-nicht',
            'lastName' => 'Fremd',
            'consent' => true,
        ], 'application/json');

        self::assertSame(404, $antwort->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Contact::class)->count([]));
    }

    /**
     * Der Honeypot antwortet wie bei Erfolg, damit ein Bot nicht lernt, dass
     * er aufgeflogen ist — anlegen darf er trotzdem nichts.
     */
    public function testHoneypotAntwortetFreundlichUndLegtNichtsAn(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $formular = $this->formular($a);

        $antwort = $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formular->getToken(),
            'lastName' => 'Bot',
            'consent' => true,
            'website' => 'http://spam.invalid',
        ], 'application/json');

        self::assertSame(202, $antwort->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Contact::class)->count([]));
    }

    public function testRateLimitGreift(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $formular = $this->formular($a);

        $codes = [];
        for ($i = 0; $i < 8; ++$i) {
            $codes[] = $this->anfrage('POST', '/api/public/leads', null, [
                'token' => $formular->getToken(),
                'lastName' => 'Viel' . $i,
                'email' => sprintf('v%d@test.invalid', $i),
                'consent' => true,
            ], 'application/json')->getStatusCode();
        }

        self::assertContains(429, $codes, 'Nach mehreren Einsendungen muss abgeriegelt werden.');
        self::assertLessThan(8, $this->em->getRepository(Contact::class)->count([]));
    }

    /**
     * Analyse.md C44: Der Mandantenfilter steht bei einer anonymen Anfrage
     * auf "zu" (tenant_id = 0). `MailerFactory::findSetting()` filtert
     * zusaetzlich explizit nach dem Mandanten — beide Bedingungen werden
     * von Doctrine UND-verknuepft, ohne ausdrueckliches Abschalten des
     * Filters fand der oeffentliche Lead-Endpunkt seinen eigenen Versandweg
     * deshalb nie. Live entdeckt: /api/mail/test (mit angemeldetem
     * Benutzer) gelang, derselbe Versandweg blieb aus dem anonymen
     * Lead-Endpunkt heraus unsichtbar.
     *
     * Der Host ist absichtlich eine private Adresse: das laesst die
     * SSRF-Pruefung sofort und ohne Netzwerkzugriff scheitern. Es geht
     * hier nicht darum, ob der Versand gelingt, sondern ob die
     * Einstellung ueberhaupt GEFUNDEN wird — erkennbar daran, welche der
     * beiden Aufgaben entsteht.
     */
    public function testOeffentlicherEndpunktFindetDenEigenenVersandweg(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $this->versandweg($a);
        $formular = $this->formular($a);

        $this->anfrage('POST', '/api/public/leads', null, [
            'token' => $formular->getToken(),
            'lastName' => 'Findet',
            'email' => 'findet@test.invalid',
            'consent' => true,
        ], 'application/json');

        $aufgabe = $this->em->getConnection()->fetchOne(
            "SELECT subject FROM activity a JOIN contact c ON c.id = a.contact_id WHERE c.last_name = 'Findet'"
        );

        self::assertNotSame(
            'Kein Versandweg hinterlegt — Bestätigung nicht verschickt',
            $aufgabe,
            'findSetting() haette den vorhandenen Versandweg finden muessen (C44).'
        );
        self::assertSame('Bestätigungsmail konnte nicht zugestellt werden', $aufgabe);
    }

    private function versandweg(Tenant $mandant): MailSetting
    {
        $einstellung = (new MailSetting())
            ->setProvider('smtp')
            ->setHost('10.0.0.5') // privat, SSRF-Pruefung greift ohne Netzwerkzugriff
            ->setPort(587)
            ->setUsername('irrelevant')
            ->setFromAddress('crm@example.invalid')
            ->setFromName('Test')
            ->setActive(true);
        $einstellung->setTenant($mandant);
        $einstellung->setSecret(
            self::getContainer()->get(\App\Service\SecretBox::class)->encrypt('irrelevant')
        );

        $this->em->persist($einstellung);
        $this->em->flush();

        return $einstellung;
    }

    /**
     * Den Token vergibt LeadForm im Konstruktor selbst — bewusst ohne Setter,
     * damit ihn niemand von aussen setzen kann. Der Test liest ihn deshalb ab,
     * statt ihn vorzugeben.
     */
    private function formular(Tenant $mandant): LeadForm
    {
        $formular = (new LeadForm())
            ->setName('Kontaktformular')
            ->setActive(true)
            ->setConsentText('Ich bin einverstanden.');
        $formular->setTenant($mandant);

        $this->em->persist($formular);
        $this->em->flush();

        return $formular;
    }
}
