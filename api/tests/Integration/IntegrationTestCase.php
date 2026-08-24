<?php

namespace App\Tests\Integration;

use App\Entity\Pipeline;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Grundlage der Funktionstests: echte Anfragen durch den Kernel, echte
 * Datenbank, echte Rechte- und Mandantenpruefung.
 *
 * Bewusst ohne zusaetzliche Testpakete (browser-kit, ApiTestCase). Der
 * Kernel nimmt eine Request entgegen und liefert eine Response — damit
 * laufen Routing, Security, Serializer und Doctrine-Filter genauso wie im
 * Betrieb, ohne dass neue Abhaengigkeiten dazukommen.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    private static bool $geprueft = false;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->sicherstellenDassTestDatenbank();
        $this->datenbankLeeren();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    /**
     * Notbremse: Die Tests raeumen zwischen den Faellen alle Tabellen leer.
     * Zeigte die Verbindung versehentlich auf die Produktivdatenbank, waeren
     * die echten Daten weg. Deshalb wird der Name vorher geprueft — lieber
     * ein abgebrochener Testlauf als ein leeres CRM.
     */
    private function sicherstellenDassTestDatenbank(): void
    {
        $name = $this->em->getConnection()->getDatabase();

        if (!is_string($name) || !str_ends_with($name, '_test')) {
            self::fail(sprintf(
                'Die Tests laufen gegen die Datenbank "%s". Erwartet wird ein Name, '
                . 'der auf "_test" endet. Abbruch, bevor Daten geloescht werden.',
                $name ?? 'unbekannt'
            ));
        }

        self::$geprueft = true;
    }

    private function datenbankLeeren(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($db->createSchemaManager()->listTableNames() as $tabelle) {
            $db->executeStatement(sprintf('TRUNCATE TABLE `%s`', $tabelle));
        }

        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();
    }

    // ------------------------------------------------------------ Fixtures

    protected function mandant(string $name, string $slug): Tenant
    {
        $tenant = (new Tenant())->setName($name)->setSlug($slug)->setActive(true);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * @param string[] $rechte
     * @param string[] $rollen
     */
    protected function benutzer(
        Tenant $mandant,
        string $benutzername,
        array $rechte = [],
        array $rollen = ['ROLE_USER'],
    ): User {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setUsername($benutzername)
            ->setDisplayName($benutzername)
            ->setRoles($rollen)
            ->setPermissions($rechte)
            ->setActive(true);
        $user->setTenant($mandant);
        $user->setPassword($hasher->hashPassword($user, 'Test!2026'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Die Startpipeline, die jeder Mandant automatisch bekommt. */
    protected function startpipeline(Tenant $mandant): Pipeline
    {
        $pipelines = $this->em->getRepository(Pipeline::class)->findBy(['tenant' => $mandant]);
        self::assertNotEmpty($pipelines, 'Ein neuer Mandant muss eine Startpipeline bekommen.');

        return $pipelines[0];
    }

    /**
     * Eine Phase des Mandanten. Ohne Namen die erste nach Reihenfolge.
     * Bewusst ueber das Repository statt ueber $pipeline->getStages(): so
     * haengt der Test nicht davon ab, ob die Sammlung im Speicher gefuellt ist.
     */
    protected function phase(Tenant $mandant, ?string $name = null): \App\Entity\Stage
    {
        $kriterien = ['tenant' => $mandant];
        if ($name !== null) {
            $kriterien['name'] = $name;
        }

        $phase = $this->em->getRepository(\App\Entity\Stage::class)
            ->findOneBy($kriterien, ['position' => 'ASC']);

        self::assertNotNull($phase, sprintf('Phase "%s" nicht gefunden.', $name ?? 'erste'));

        return $phase;
    }

    // ------------------------------------------------------------- Anfragen

    /**
     * Schickt eine Anfrage durch den Kernel — angemeldet, wenn ein Benutzer
     * uebergeben wird.
     *
     * @param array<string, mixed>|null $daten
     */
    protected function anfrage(
        string $methode,
        string $pfad,
        ?User $als = null,
        ?array $daten = null,
        string $typ = 'application/ld+json',
    ): Response {
        // Vor jeder Anfrage den Speicher des EntityManagers leeren. Sonst
        // beantwortet Doctrine ein find() aus dem Identity Map, ohne die
        // Datenbank und damit ohne den Mandantenfilter zu befragen — im
        // Betrieb hat jede Anfrage einen frischen EntityManager, der Test
        // wuerde also etwas beweisen, das dort nicht gilt.
        $this->em->clear();

        $kopf = ['CONTENT_TYPE' => $typ, 'HTTP_ACCEPT' => 'application/ld+json'];

        if ($als !== null) {
            $kopf['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tokenFuer($als);
        }

        $request = Request::create(
            $pfad,
            $methode,
            [],
            [],
            [],
            $kopf,
            $daten === null ? null : json_encode($daten, JSON_THROW_ON_ERROR),
        );

        // Jede Anfrage braucht einen frischen Kernel: der vorige hat den
        // Sicherheitskontext des letzten Benutzers noch stehen.
        $kernel = self::$kernel instanceof KernelInterface ? self::$kernel : self::bootKernel();

        return $kernel->handle($request);
    }

    /**
     * Anfrage mit Dateianhang (multipart/form-data). Der Import laeuft so —
     * Datei plus ein JSON-Feld mit der Feldzuordnung im selben Request.
     *
     * @param array<string, string> $felder
     */
    protected function anfrageMitDatei(
        string $pfad,
        User $als,
        string $inhalt,
        string $dateiname = 'import.csv',
        array $felder = [],
    ): Response {
        $this->em->clear();

        $temp = tempnam(sys_get_temp_dir(), 'test_import_');
        self::assertIsString($temp);
        file_put_contents($temp, $inhalt);

        $datei = new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $temp,
            $dateiname,
            'text/csv',
            null,
            true, // Testmodus: die Datei wurde nicht wirklich hochgeladen
        );

        $request = Request::create(
            $pfad,
            'POST',
            $felder,
            [],
            ['file' => $datei],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFuer($als)],
        );

        $kernel = self::$kernel instanceof KernelInterface ? self::$kernel : self::bootKernel();

        try {
            return $kernel->handle($request);
        } finally {
            @unlink($temp);
        }
    }

    protected function tokenFuer(User $user): string
    {
        return self::getContainer()
            ->get('lexik_jwt_authentication.jwt_manager')
            ->create($user);
    }

    /** @return array<string, mixed> */
    protected function inhalt(Response $antwort): array
    {
        $roh = $antwort->getContent();
        self::assertIsString($roh);

        return json_decode($roh, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
