<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ImportAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Kontakt-Import in vier Schritten (siehe TODO.md A11):
 * 1) analyze  — Datei lesen, Kopfzeile + Vorschau + Zuordnungsvorschlag
 * 2) (Frontend) Nutzer prueft/korrigiert die Zuordnung
 * 3) (Frontend) Vorschau der ersten Zeilen mit der gewaehlten Zuordnung
 * 4) execute  — Datei + (ggf. korrigierte) Zuordnung, legt Kontakte an
 *
 * Die API ist zustandslos: es gibt keinen Server-Zwischenspeicher zwischen
 * Schritt 1 und Schritt 4 — die Datei wird bei /execute erneut mitgeschickt.
 *
 * DSGVO (zwingend, siehe TODO.md A11):
 * - importierte Kontakte bekommen source = 'import' (Herkunft bleibt benennbar)
 * - es wird KEINE Einwilligung gesetzt — der Import darf kein Weg sein, die
 *   Einwilligungspflicht zu umgehen (siehe Kommentar in execute())
 *
 * Der Mandant kommt wie ueberall aus dem angemeldeten Benutzer, nie aus dem
 * Request (Context.md, Mandanten-Modell); TenantAssignListener setzt ihn
 * beim Anlegen automatisch.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ImportAnalyzer $analyzer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** Schritt 1: Datei lesen, Kopfzeile + fuenf Vorschauzeilen + Zuordnungsvorschlag. */
    #[Route('/api/import/analyze', name: 'import_analyze', methods: ['POST'])]
    public function analyze(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->fehler('Bitte eine Datei auswählen.', 400);
        }

        try {
            $ergebnis = $this->analyzer->analyze($file);
        } catch (\RuntimeException $e) {
            return $this->fehler($e->getMessage(), 422);
        }

        return new JsonResponse($ergebnis);
    }

    /**
     * Schritt 4: Uebernahme mit der vom Nutzer geprueften Zuordnung.
     *
     * `mapping` ist ein JSON-Feld im selben multipart/form-data-Request wie
     * die Datei: ein Array in der Reihenfolge der Kopfzeile aus Schritt 1,
     * je Eintrag ein Zielfeld (ImportAnalyzer::TARGET_FIELDS) oder "ignore".
     */
    #[Route('/api/import/execute', name: 'import_execute', methods: ['POST'])]
    public function execute(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->fehler('Bitte eine Datei auswählen.', 400);
        }

        $mappingRoh = $request->request->get('mapping');
        $mapping = is_string($mappingRoh) ? json_decode($mappingRoh, true) : null;
        if (!is_array($mapping) || count($mapping) === 0) {
            return $this->fehler('Bitte eine gültige Feldzuordnung übermitteln.', 422);
        }

        try {
            $daten = $this->analyzer->read($file);
        } catch (\RuntimeException $e) {
            return $this->fehler($e->getMessage(), 422);
        }

        if (count($mapping) !== count($daten['headers'])) {
            return $this->fehler('Die Zuordnung passt nicht mehr zur Datei.', 422);
        }

        // Spaltenindex je Zielfeld ermitteln; zeigen mehrere Spalten auf
        // dasselbe Feld, gilt die erste — der Rest wurde vom Nutzer
        // versehentlich doppelt zugeordnet.
        $indexJeFeld = [];
        foreach (array_values($mapping) as $i => $feld) {
            if (in_array($feld, ImportAnalyzer::TARGET_FIELDS, true) && !isset($indexJeFeld[$feld])) {
                $indexJeFeld[$feld] = $i;
            }
        }

        $wert = static function (array $zeile, string $feld) use ($indexJeFeld): ?string {
            if (!isset($indexJeFeld[$feld])) {
                return null;
            }
            $v = trim((string) ($zeile[$indexJeFeld[$feld]] ?? ''));

            return $v === '' ? null : $v;
        };

        $benutzer = $this->security->getUser();
        $tenant = $benutzer instanceof User ? $benutzer->getTenant() : null;

        $vorhandeneEmails = $this->bekannteEmails($tenant);
        $firmenCache = [];

        $importiert = [];
        $duplikate = [];
        $fehlerListe = [];

        foreach ($daten['rows'] as $i => $zeile) {
            $zeilennummer = $i + 2; // 1 = Kopfzeile

            $email = $wert($zeile, 'email');

            $kontakt = new Contact();
            $kontakt->setFirstName($wert($zeile, 'firstName'));
            $kontakt->setLastName($wert($zeile, 'lastName') ?? '');
            $kontakt->setEmail($email);
            $kontakt->setPhone($wert($zeile, 'phone'));
            $kontakt->setPosition($wert($zeile, 'position'));
            $kontakt->setDepartment($wert($zeile, 'department'));
            $kontakt->setStatus('neu');
            $kontakt->setSource('import');
            // DSGVO, zwingend (TODO.md A11): KEINE Einwilligung setzen.
            // consentGivenAt/consentText bleiben leer — ein Import ist keine
            // Einwilligung und darf nicht als Umweg um die Einwilligungspflicht
            // dienen. Importierte Kontakte sind bis zu einer echten,
            // eigenstaendigen Einwilligung nicht bewerbbar (isContactable() = false).

            $verstoesse = $this->validator->validate($kontakt);
            if (count($verstoesse) > 0) {
                $fehlerListe[] = ['row' => $zeilennummer, 'reason' => (string) $verstoesse[0]->getMessage()];
                continue;
            }

            if ($email !== null) {
                $schluessel = mb_strtolower($email);
                if (isset($vorhandeneEmails[$schluessel])) {
                    $duplikate[] = ['row' => $zeilennummer, 'email' => $email, 'name' => $kontakt->getDisplayName()];
                    continue;
                }
            }

            $firmenName = $wert($zeile, 'company');
            if ($firmenName !== null) {
                $kontakt->setCompany($this->firmaFinden($firmenName, $tenant, $firmenCache));
            }

            $this->em->persist($kontakt);
            if ($email !== null) {
                $vorhandeneEmails[mb_strtolower($email)] = true;
            }
            $importiert[] = ['row' => $zeilennummer, 'name' => $kontakt->getDisplayName(), 'email' => $email];
        }

        $this->em->flush();

        return new JsonResponse([
            'summary' => [
                'totalRows' => count($daten['rows']),
                'imported' => count($importiert),
                'skipped' => count($duplikate),
                'failed' => count($fehlerListe),
            ],
            'imported' => $importiert,
            'skippedDuplicates' => $duplikate,
            'errors' => $fehlerListe,
        ]);
    }

    /**
     * E-Mail-Adressen, die im Mandanten des angemeldeten Benutzers bereits
     * existieren — Grundlage fuer die Dublettenerkennung (gleiche E-Mail im
     * selben Mandanten wird uebersprungen, nicht ueberschrieben).
     *
     * @return array<string, true> klein geschrieben als Schluessel
     */
    private function bekannteEmails(?Tenant $tenant): array
    {
        $emails = [];
        foreach ($this->em->getRepository(Contact::class)->findBy(['tenant' => $tenant]) as $kontakt) {
            if ($kontakt->getEmail() !== null) {
                $emails[mb_strtolower($kontakt->getEmail())] = true;
            }
        }

        return $emails;
    }

    /**
     * Firma per Name im Mandanten finden, sonst neu anlegen und verknuepfen
     * (TODO.md A11). $cache haelt schon gefundene/angelegte Firmen innerhalb
     * desselben Imports fest, damit dieselbe Firma nicht mehrfach angelegt wird.
     *
     * @param array<string, Company> $cache
     */
    private function firmaFinden(string $name, ?Tenant $tenant, array &$cache): Company
    {
        $schluessel = mb_strtolower($name);
        if (isset($cache[$schluessel])) {
            return $cache[$schluessel];
        }

        $firma = $this->em->getRepository(Company::class)->findOneBy(['tenant' => $tenant, 'name' => $name]);
        if ($firma === null) {
            $firma = (new Company())->setName($name);
            $this->em->persist($firma);
        }

        $cache[$schluessel] = $firma;

        return $firma;
    }

    private function fehler(string $text, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $text], $status);
    }
}
