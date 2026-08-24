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
 * Contact import in four steps:
 * 1) analyze  — read the file, header row + preview + mapping suggestion
 * 2) (frontend) user reviews/corrects the mapping
 * 3) (frontend) preview of the first rows with the chosen mapping
 * 4) execute  — file + (possibly corrected) mapping, creates the contacts
 *
 * The API is stateless: there is no server-side cache between step 1 and
 * step 4 — the file is sent again with /execute.
 *
 * GDPR (mandatory):
 * - imported contacts get source = 'import' (the origin stays traceable)
 * - NO consent is set — import must not be a way to bypass the consent
 *   requirement (see the comment in execute())
 *
 * As everywhere, the tenant comes from the logged-in user, never from
 * the request; TenantAssignListener sets it automatically on creation.
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

    /** Step 1: read the file, header row + five preview rows + mapping suggestion. */
    #[Route('/api/import/analyze', name: 'import_analyze', methods: ['POST'])]
    public function analyze(Request $request): Response
    {
        // Class-level attributes are not evaluated here — hence the
        // explicit check, so the permission actually takes effect.
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
     * Step 4: commit using the mapping the user reviewed.
     *
     * `mapping` is a JSON field in the same multipart/form-data request as
     * the file: an array in the order of the header row from step 1, one
     * target field per entry (ImportAnalyzer::TARGET_FIELDS) or "ignore".
     */
    #[Route('/api/import/execute', name: 'import_execute', methods: ['POST'])]
    public function execute(Request $request): Response
    {
        // Class-level attributes are not evaluated here — hence the
        // explicit check, so the permission actually takes effect.
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

        // Determine the column index per target field; if several columns
        // point to the same field, the first one wins — the rest were
        // accidentally mapped twice by the user.
        $indexJeFeld = [];
        $indexJeZusatzfeld = [];
        foreach (array_values($mapping) as $i => $feld) {
            if (in_array($feld, ImportAnalyzer::TARGET_FIELDS, true) && !isset($indexJeFeld[$feld])) {
                $indexJeFeld[$feld] = $i;
                continue;
            }

            // Custom fields arrive as "custom.<key>".
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

        // Decisions from the preview, by row number:
        //   {"5": {"action": "aktualisieren", "contactId": 17}}
        // If a row is missing, the previous behavior applies: a matching
        // email is skipped. That way a call without a preview stays
        // valid unchanged and never creates duplicates unasked.
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
            $zeilennummer = $i + 2; // 1 = header row

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

            // Adopt custom fields and validate them against their
            // definition — the same rules apply on import as on manual
            // creation, otherwise import would be a way around
            // validation.
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
            // GDPR, mandatory: set NO consent. consentGivenAt/consentText
            // stay empty — an import is not consent and must not serve
            // as a detour around the consent requirement. Imported
            // contacts are not marketable (isContactable() = false)
            // until a real, standalone consent is given.

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

            // Without an explicit decision, the previous behavior
            // applies: an already known email is skipped.
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
     * Email addresses that already exist in the logged-in user's tenant
     * — the basis for duplicate detection (a matching email within the
     * same tenant is skipped, not overwritten).
     *
     * @return array<string, true> lowercased as the key
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
     * Finds a company by name within the tenant, or creates and links a
     * new one. $cache tracks companies already found/created within the
     * same import, so the same company is not created more than once.
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
     * Reads the file and translates the mapping into column indexes —
     * shared between preview and commit, so both are guaranteed to read
     * the same row the same way. Returns either ['fehler' => JsonResponse]
     * or the parsed components.
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

        // Determine the column index per target field; if several columns
        // point to the same field, the first one wins — the rest were
        // accidentally mapped twice by the user.
        $indexJeFeld = [];
        $indexJeZusatzfeld = [];
        foreach (array_values($mapping) as $i => $feld) {
            if (in_array($feld, ImportAnalyzer::TARGET_FIELDS, true) && !isset($indexJeFeld[$feld])) {
                $indexJeFeld[$feld] = $i;
                continue;
            }

            // Custom fields arrive as "custom.<key>".
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
     * Step 3: preview with a check against existing records.
     *
     * Shows, per row, whether the customer possibly already exists, so a
     * human can decide: fill in gaps or create new. Changes nothing —
     * read-only.
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

            $zeilennummer = $i + 2; // 1 = header row
            $firmenName = $wert($zeile, 'company');
            $treffer = $this->matcher->treffer($kontakt, $firmenName);
            if ($treffer !== []) {
                ++$mitTreffer;
            }

            // If the same person already appears earlier in the SAME
            // file, the second row is suggested to be skipped —
            // otherwise a single run would create two contacts.
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
     * Fetches the existing contact to fill in and explicitly checks the
     * tenant — not just via the Doctrine filter, which is switched off
     * for ROLE_SUPERADMIN: an implicit protection is no protection at
     * all once a role can bypass it.
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
     * Fills in missing data on the existing contact from the import row.
     *
     * Only EMPTY fields are filled — existing ones are left untouched.
     * Same rule as when merging duplicates: an import file is not a
     * better source of truth than the maintained record, and someone who
     * has corrected a phone number over the years does not want it
     * overwritten by an old list.
     *
     * This method deliberately does NOT touch consent fields: an import
     * does not constitute consent, not even by the detour of "filling in
     * an existing contact".
     *
     * @return list<string> labels of the fields actually filled in
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

        // Custom fields: also only fill gaps.
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
