<?php

namespace App\Tests\Integration;

use App\Entity\Deal;
use App\Entity\Pipeline;
use App\Entity\Stage;

/**
 * Die Regeln aus A5 waren bisher nur von Hand ueber die API belegt. Hier
 * stehen sie dauerhaft: Loeschen ist Adminsache, eine belegte Phase bleibt
 * stehen, und die letzte Pipeline eines Mandanten darf nicht verschwinden.
 */
final class PipelineVerwaltungTest extends IntegrationTestCase
{
    public function testPipelineAnlegenUndPhaseErgaenzen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'pipelines.manage'], ['ROLE_ADMIN']);

        $antwort = $this->anfrage('POST', '/api/pipelines', $admin, ['name' => 'Wartung', 'position' => 1]);
        self::assertSame(201, $antwort->getStatusCode(), (string) $antwort->getContent());
        $pipelineId = $this->inhalt($antwort)['id'];

        $phase = $this->anfrage('POST', '/api/stages', $admin, [
            'name' => 'Anfrage',
            'art' => 'offen',
            'position' => 0,
            'pipeline' => '/api/pipelines/' . $pipelineId,
        ]);

        self::assertSame(201, $phase->getStatusCode(), (string) $phase->getContent());
        self::assertSame('offen', $this->inhalt($phase)['art']);
    }

    public function testUnbekannteArtWirdAbgelehnt(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'pipelines.manage'], ['ROLE_ADMIN']);
        $pipeline = $this->startpipeline($a);

        $antwort = $this->anfrage('POST', '/api/stages', $admin, [
            'name' => 'Irgendwas',
            'art' => 'ausgedacht',
            'position' => 9,
            'pipeline' => '/api/pipelines/' . $pipeline->getId(),
        ]);

        self::assertSame(422, $antwort->getStatusCode());
    }

    /**
     * Zusammenfuehren und Loeschen entfernen Daten unwiderruflich. Dafuer
     * genuegt das Einrichtungsrecht nicht (Analyse.md C17).
     */
    public function testLoeschenVerlangtAdminrecht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $ohneAdmin = $this->benutzer($a, 'einrichter', ['deals.view', 'pipelines.manage']);
        $phase = $this->phase($a, 'Verloren');

        $antwort = $this->anfrage('DELETE', '/api/stages/' . $phase->getId(), $ohneAdmin);

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM stage WHERE id = ?', [$phase->getId()]));
    }

    public function testPhaseMitVorgangBleibtStehen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'deals.manage', 'pipelines.manage'], ['ROLE_ADMIN']);
        $phase = $this->phase($a, 'Neu');

        $vorgang = (new Deal())->setTitle('Haengt an der Phase');
        $vorgang->setStage($phase);
        $vorgang->setTenant($a);
        $this->em->persist($vorgang);
        $this->em->flush();

        $antwort = $this->anfrage('DELETE', '/api/stages/' . $phase->getId(), $admin);

        self::assertSame(409, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertStringContainsString('umhaengen', (string) $antwort->getContent());
        self::assertSame(1, (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM stage WHERE id = ?', [$phase->getId()]));
    }

    public function testLetztePipelineBleibtBestehen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'pipelines.manage'], ['ROLE_ADMIN']);
        $einzige = $this->startpipeline($a);

        $antwort = $this->anfrage('DELETE', '/api/pipelines/' . $einzige->getId(), $admin);

        self::assertSame(409, $antwort->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pipeline'));
    }

    /**
     * Vorher scheiterte das an der Datenbank (HTTP 500), obwohl die
     * Oberflaeche zusagt, die Phasen gingen mit (Analyse.md C25).
     */
    public function testLeerePipelineWirdMitIhrenPhasenGeloescht(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'pipelines.manage'], ['ROLE_ADMIN']);

        $zweite = $this->inhalt(
            $this->anfrage('POST', '/api/pipelines', $admin, ['name' => 'Zweite', 'position' => 1])
        );
        $this->anfrage('POST', '/api/stages', $admin, [
            'name' => 'Erste Phase',
            'art' => 'offen',
            'position' => 0,
            'pipeline' => '/api/pipelines/' . $zweite['id'],
        ]);

        $vorher = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM stage WHERE pipeline_id = ?', [$zweite['id']]);
        self::assertSame(1, $vorher);

        $antwort = $this->anfrage('DELETE', '/api/pipelines/' . $zweite['id'], $admin);

        self::assertSame(204, $antwort->getStatusCode(), (string) $antwort->getContent());
        self::assertSame(0, (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM stage WHERE pipeline_id = ?', [$zweite['id']]));
    }

    public function testReihenfolgeLaesstSichTauschen(): void
    {
        $a = $this->mandant('Mandant A', 'a');
        $admin = $this->benutzer($a, 'admin', ['deals.view', 'pipelines.manage'], ['ROLE_ADMIN']);
        $erste = $this->phase($a, 'Neu');
        $zweite = $this->phase($a, 'Qualifiziert');
        $posErste = $erste->getPosition();
        $posZweite = $zweite->getPosition();

        $this->anfrage('PATCH', '/api/stages/' . $erste->getId(), $admin, ['position' => $posZweite], 'application/merge-patch+json');
        $this->anfrage('PATCH', '/api/stages/' . $zweite->getId(), $admin, ['position' => $posErste], 'application/merge-patch+json');

        $db = $this->em->getConnection();
        self::assertSame($posZweite, (int) $db->fetchOne('SELECT position FROM stage WHERE id = ?', [$erste->getId()]));
        self::assertSame($posErste, (int) $db->fetchOne('SELECT position FROM stage WHERE id = ?', [$zweite->getId()]));
    }
}
