<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\CustomFieldValidator;
use App\Service\ImportAnalyzer;
use App\Service\ImportMatcher;
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
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ImportAnalyzer $analyzer,
        private readonly ValidatorInterface $validator,
        private readonly CustomFieldValidator $customFields,
        private readonly ImportMatcher $matcher,
    ) {
    }

    /** Schritt 1: Datei lesen, Kopfzeile + fuenf Vorschauzeilen + Zuordnungsvorschlag. */
    #[Route('/api/import/analyze', name: 'import_analyze', methods: ['POST'])]
    public function analyze(Request $request): Response
    {
        // Klassen-Attribute wurden hier nicht ausgewertet — deshalb
        // ausdrücklich prüfen, damit die Rechte sicher greifen.
        $this->denyAccessUnlessGranted('PERM', 'importexport.use');

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->fehler('Bitte eine Datei auswählen.', 400);
        }

        try {
            $benutzerA = $this->security->getUser();
            $ergebnis = $this->analyzer->analyze(
                $file,
                $this->customFields->definitionen('contact', $benutzerA instanceof User ? $benutzerA->getTenant() : null),
            );
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
        // Klassen-Attribute wurden hier nicht ausgewertet — deshalb
        // ausdrücklich prüfen, damit die Rechte sicher greifen.
        $this->denyAccessUnlessGranted('PERM', 'importexport.use');

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
        $indexJeZusatzfeld = [];
        foreach (array_values($mapping) as $i => $feld) {
            if (in_array($feld, ImportAnalyzer::TARGET_FIELDS, true) && !isset($indexJeFeld[$feld])) {
                $indexJeFeld[$feld] = $i;
                continue;
            }

            // Selbst angelegte Felder kommen als "custom.<schluessel>".
            if (is_string($feld) && str_starts_with($feld, 'custom.')) {
                $schluessel = substr($feld, 7);
                if (!isset($indexJeZusatzfeld[$schluessel])) {
                    $indexJeZusatzfeld[$schluessel] = $i;
                }
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

        // Entscheidungen aus der Vorschau, je Zeilennummer:
        //   {"5": {"action": "aktualisieren", "contactId": 17}}
        // Fehlt eine Zeile, gilt das bisherige Verhalten: gleiche E-Mail wird
        // uebersprungen. So bleibt ein Aufruf ohne Vorschau unveraendert
        // gueltig und legt nie ungefragt Doppelte an.
        $entscheidungenRoh = $request->request->get('decisions');
        $entscheidungen = is_string($entscheidungenRoh) ? json_decode($entscheidungenRoh, true) : [];
        if (!is_array($entscheidungen)) {
            return $this->fehler('Die Entscheidungen konnten nicht gelesen werden.', 422);
        }

        $vorhandeneEmails = $this->bekannteEmails($tenant);
        $firmenCache = [];

        $importiert = [];
        $aktualisiert = [];
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

            // Zusatzfelder uebernehmen und gegen ihre Definition pruefen —
            // beim Import gelten dieselben Regeln wie beim Anlegen von Hand,
            // sonst waere der Import ein Weg an der Pruefung vorbei.
            if ($indexJeZusatzfeld !== []) {
                $roh = [];
                foreach ($indexJeZusatzfeld as $schluessel => $spalte) {
                    $v = trim((string) ($zeile[$spalte] ?? ''));
                    if ($v !== '') {
                        $roh[$schluessel] = $v;
                    }
                }

                $geprueft = $this->customFields->pruefen($roh, 'contact', $tenant);
                if ($geprueft['fehler'] !== []) {
                    $fehlerListe[] = ['row' => $zeilennummer, 'reason' => implode(' ', $geprueft['fehler'])];
                    continue;
                }

                $kontakt->setCustomData($geprueft['werte']);
            }
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

            $entscheidung = $entscheidungen[(string) $zeilennummer] ?? $entscheidungen[$zeilennummer] ?? null;
            $aktion = is_array($entscheidung) ? ($entscheidung['action'] ?? null) : null;

            if ($aktion === 'ueberspringen') {
                $duplikate[] = [
                    'row' => $zeilennummer,
                    'email' => $email,
                    'name' => $kontakt->getDisplayName(),
                    'grund' => 'übersprungen',
                ];
                continue;
            }

            if ($aktion === 'aktualisieren') {
                $ziel = $this->bestandskontakt($entscheidung['contactId'] ?? null, $tenant);
                if ($ziel === null) {
                    $fehlerListe[] = [
                        'row' => $zeilennummer,
                        'reason' => 'Der zu ergänzende Kontakt wurde nicht gefunden.',
                    ];
                    continue;
                }

                $gefuellt = $this->ergaenzen($ziel, $kontakt);
                $aktualisiert[] = [
                    'row' => $zeilennummer,
                    'id' => $ziel->getId(),
                    'name' => $ziel->getDisplayName(),
                    'ergaenzteFelder' => $gefuellt,
                ];
                continue;
            }

            // Ohne ausdrueckliche Entscheidung bleibt es beim bisherigen
            // Verhalten: eine bereits bekannte E-Mail wird uebersprungen.
            if ($aktion !== 'neu' && $email !== null) {
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
                'updated' => count($aktualisiert),
                'skipped' => count($duplikate),
                'failed' => count($fehlerListe),
            ],
            'imported' => $importiert,
            'updated' => $aktualisiert,
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

    /**
     * Datei lesen und die Zuordnung in Spaltenindizes uebersetzen — geteilt
     * von Vorschau und Uebernahme, damit beide garantiert dieselbe Zeile
     * gleich lesen. Liefert entweder ['fehler' => JsonResponse] oder die
     * ausgewerteten Bestandteile.
     *
     * @return array{fehler?: JsonResponse, daten?: array, wert?: callable, zusatz?: array<string, int>}
     */
    private function dateiUndZuordnung(Request $request): array
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return ['fehler' => $this->fehler('Bitte eine Datei auswählen.', 400)];
        }

        $mappingRoh = $request->request->get('mapping');
        $mapping = is_string($mappingRoh) ? json_decode($mappingRoh, true) : null;
        if (!is_array($mapping) || count($mapping) === 0) {
            return ['fehler' => $this->fehler('Bitte eine gültige Feldzuordnung übermitteln.', 422)];
        }

        try {
            $daten = $this->analyzer->read($file);
        } catch (\RuntimeException $e) {
            return ['fehler' => $this->fehler($e->getMessage(), 422)];
        }

        if (count($mapping) !== count($daten['headers'])) {
            return ['fehler' => $this->fehler('Die Zuordnung passt nicht mehr zur Datei.', 422)];
        }

        // Spaltenindex je Zielfeld ermitteln; zeigen mehrere Spalten auf
        // dasselbe Feld, gilt die erste — der Rest wurde vom Nutzer
        // versehentlich doppelt zugeordnet.
        $indexJeFeld = [];
        $indexJeZusatzfeld = [];
        foreach (array_values($mapping) as $i => $feld) {
            if (in_array($feld, ImportAnalyzer::TARGET_FIELDS, true) && !isset($indexJeFeld[$feld])) {
                $indexJeFeld[$feld] = $i;
                continue;
            }

            // Selbst angelegte Felder kommen als "custom.<schluessel>".
            if (is_string($feld) && str_starts_with($feld, 'custom.')) {
                $schluessel = substr($feld, 7);
                if (!isset($indexJeZusatzfeld[$schluessel])) {
                    $indexJeZusatzfeld[$schluessel] = $i;
                }
            }
        }

        $wert = static function (array $zeile, string $feld) use ($indexJeFeld): ?string {
            if (!isset($indexJeFeld[$feld])) {
                return null;
            }
            $v = trim((string) ($zeile[$indexJeFeld[$feld]] ?? ''));

            return $v === '' ? null : $v;
        };

        return ['daten' => $daten, 'wert' => $wert, 'zusatz' => $indexJeZusatzfeld];
    }

    /**
     * Schritt 3: Vorschau mit Abgleich gegen den Bestand.
     *
     * Zeigt je Zeile, ob der Kunde moeglicherweise schon existiert, damit ein
     * Mensch entscheiden kann: ergaenzen oder neu anlegen (Alexander, 24.08.).
     * Aendert nichts — reine Auskunft.
     */
    #[Route('/api/import/preview', name: 'import_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->denyAccessUnlessGranted('PERM', 'importexport.use');

        $teile = $this->dateiUndZuordnung($request);
        if (isset($teile['fehler'])) {
            return $teile['fehler'];
        }

        $benutzer = $this->security->getUser();
        $tenant = $benutzer instanceof User ? $benutzer->getTenant() : null;
        $this->matcher->vorbereiten($tenant);

        $zeilen = [];
        $mitTreffer = 0;

        foreach ($teile['daten']['rows'] as $i => $zeile) {
            $wert = $teile['wert'];
            $kontakt = (new Contact())
                ->setFirstName($wert($zeile, 'firstName'))
                ->setLastName($wert($zeile, 'lastName') ?? '')
                ->setEmail($wert($zeile, 'email'));

            $zeilennummer = $i + 2; // 1 = Kopfzeile
            $firmenName = $wert($zeile, 'company');
            $treffer = $this->matcher->treffer($kontakt, $firmenName);
            if ($treffer !== []) {
                ++$mitTreffer;
            }

            // Steht derselbe Mensch schon weiter oben in DERSELBEN Datei,
            // wird die zweite Zeile vorgeschlagen zu ueberspringen — sonst
            // entstuenden aus einem Lauf zwei Kontakte.
            $dateiDublette = $this->matcher->dateiDublette($kontakt, $firmenName);
            $this->matcher->merken($zeilennummer, $kontakt, $firmenName);

            $vorschlag = match (true) {
                $dateiDublette !== null => 'ueberspringen',
                $treffer !== [] => 'aktualisieren',
                default => 'neu',
            };

            $zeilen[] = [
                'row' => $zeilennummer,
                'name' => $kontakt->getDisplayName(),
                'email' => $kontakt->getEmail(),
                'firma' => $firmenName,
                'dateiDublette' => $dateiDublette,
                'vorschlag' => $vorschlag,
                'treffer' => array_map(static fn (array $t) => [
                    'id' => $t['kontakt']->getId(),
                    'name' => $t['kontakt']->getDisplayName(),
                    'email' => $t['kontakt']->getEmail(),
                    'firma' => $t['kontakt']->getCompany()?->getName(),
                    'sicherheit' => $t['sicherheit'],
                    'grund' => $t['grund'],
                ], $treffer),
            ];
        }

        return new JsonResponse([
            'summary' => [
                'totalRows' => count($teile['daten']['rows']),
                'withMatches' => $mitTreffer,
                'inFileDuplicates' => count(array_filter($zeilen, static fn (array $z) => $z['dateiDublette'] !== null)),
            ],
            'rows' => $zeilen,
        ]);
    }

    /**
     * Holt den zu ergaenzenden Bestandskontakt und prueft ausdruecklich den
     * Mandanten — nicht nur ueber den Doctrine-Filter (Analyse.md C18/C24:
     * der Filter ist fuer ROLE_SUPERADMIN abgeschaltet, ein impliziter
     * Schutz ist keiner, wenn eine Rolle ihn aushebelt).
     */
    private function bestandskontakt(mixed $id, ?Tenant $tenant): ?Contact
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || $tenant === null) {
            return null;
        }

        $kontakt = $this->em->getRepository(Contact::class)->find($id);
        if ($kontakt === null || $kontakt->getTenant()?->getId() !== $tenant->getId()) {
            return null;
        }

        return $kontakt;
    }

    /**
     * Ergaenzt fehlende Angaben am Bestandskontakt aus der Importzeile.
     *
     * Nur LEERE Felder werden gefuellt — vorhandene bleiben unangetastet.
     * Dieselbe Regel wie beim Zusammenfuehren von Dubletten: eine Importdatei
     * ist keine bessere Wahrheit als der gepflegte Datensatz, und wer
     * jahrelang eine Telefonnummer korrigiert hat, will sie nicht durch eine
     * alte Liste ueberschrieben bekommen.
     *
     * Einwilligungsfelder fasst diese Methode bewusst NICHT an: ein Import
     * belegt keine Einwilligung (Analyse.md C6/C33), auch nicht ueber den
     * Umweg "Bestandskontakt ergaenzen".
     *
     * @return list<string> Bezeichnungen der tatsaechlich gefuellten Felder
     */
    private function ergaenzen(Contact $ziel, Contact $ausDatei): array
    {
        $felder = [
            'Vorname' => ['getFirstName', 'setFirstName'],
            'Nachname' => ['getLastName', 'setLastName'],
            'E-Mail' => ['getEmail', 'setEmail'],
            'Telefon' => ['getPhone', 'setPhone'],
            'Position' => ['getPosition', 'setPosition'],
            'Abteilung' => ['getDepartment', 'setDepartment'],
        ];

        $gefuellt = [];
        foreach ($felder as $bezeichnung => [$lesen, $schreiben]) {
            $vorhanden = $ziel->$lesen();
            $neu = $ausDatei->$lesen();

            if (($vorhanden === null || $vorhanden === '') && $neu !== null && $neu !== '') {
                $ziel->$schreiben($neu);
                $gefuellt[] = $bezeichnung;
            }
        }

        // Zusatzfelder: ebenfalls nur Luecken fuellen.
        $zielDaten = $ziel->getCustomData() ?? [];
        foreach ($ausDatei->getCustomData() ?? [] as $schluessel => $wert) {
            if (!array_key_exists($schluessel, $zielDaten) || $zielDaten[$schluessel] === null) {
                $zielDaten[$schluessel] = $wert;
                $gefuellt[] = 'Zusatzfeld ' . $schluessel;
            }
        }
        $ziel->setCustomData($zielDaten);

        return $gefuellt;
    }

    private function fehler(string $text, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $text], $status);
    }
}
