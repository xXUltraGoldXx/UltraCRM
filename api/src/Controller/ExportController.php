<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Contact;
use App\Service\CustomFieldValidator;
use App\Entity\Deal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Data export (contacts, companies, deals) as CSV or XLSX.
 *
 * IMPORTANT (GDPR): an export leaves the application's tenant boundary —
 * the generated file then lives on the user's machine and can be passed
 * on from there. From the moment of download, the user is responsible
 * for where the exported file is stored, shared and deleted, not the
 * application anymore.
 *
 * The tenant filter (`tenant_filter`) applies automatically based on the
 * logged-in user — this controller deliberately does NOT build its own
 * tenant WHERE clause. A manually added WHERE would be a second source
 * of truth, exactly the trap the tenant architecture is meant to guard
 * against.
 */
final class ExportController extends AbstractController
{
    private const STATUS_LABEL = [
        'neu' => 'Neu', 'in_kontakt' => 'In Kontakt', 'qualifiziert' => 'Qualifiziert',
        'kunde' => 'Kunde', 'kein_interesse' => 'Kein Interesse',
    ];

    private const SOURCE_LABEL = [
        'formular' => 'Formular', 'telefon' => 'Telefon', 'messe' => 'Messe',
        'empfehlung' => 'Empfehlung', 'eigene_recherche' => 'Recherche',
        'import' => 'Import', 'sonstiges' => 'Sonstiges',
    ];

    public function __construct(private readonly EntityManagerInterface $em,
        private readonly CustomFieldValidator $customFields,
    )
    {
    }

    #[Route(
        '/api/export/{typ}.{format}',
        name: 'export',
        methods: ['GET'],
        requirements: ['typ' => 'contacts|companies|deals', 'format' => 'csv|xlsx'],
    )]
    public function export(string $typ, string $format, Request $request): Response
    {
        // Class-level attributes are not evaluated here — hence the
        // explicit check, so the permission actually takes effect.
        $this->denyAccessUnlessGranted('PERM', 'importexport.use');

        [$kopf, $zeilen] = match ($typ) {
            'contacts' => $this->kontakteDaten($request),
            'companies' => $this->firmenDaten($request),
            'deals' => $this->vorgaengeDaten($request),
        };

        $dateiname = sprintf('%s-%s.%s', $typ, (new \DateTimeImmutable())->format('Y-m-d'), $format);

        return $format === 'csv'
            ? $this->csvAntwort($kopf, $zeilen, $dateiname)
            : $this->xlsxAntwort($kopf, $zeilen, $dateiname);
    }

    /**
     * @return array{0: string[], 1: array<int, array<int, string|null>>}
     */
    private function kontakteDaten(Request $request): array
    {
        $qb = $this->em->getRepository(Contact::class)->createQueryBuilder('c')
            ->leftJoin('c.company', 'comp')->addSelect('comp')
            ->orderBy('c.lastName', 'ASC');

        // Pass through the frontend's active filters where that is
        // simple to do — this does not reproduce the full API filter
        // logic.
        if ($status = $request->query->get('status')) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
        if ($source = $request->query->get('source')) {
            $qb->andWhere('c.source = :source')->setParameter('source', $source);
        }
        if ($companyId = $request->query->get('company')) {
            $qb->andWhere('comp.id = :companyId')->setParameter('companyId', $companyId);
        }
        if ($lastName = $request->query->get('lastName')) {
            $qb->andWhere('c.lastName LIKE :lastName')->setParameter('lastName', '%' . $lastName . '%');
        }
        if ($department = $request->query->get('department')) {
            $qb->andWhere('c.department LIKE :department')->setParameter('department', '%' . $department . '%');
        }

        // Append custom fields at the end — otherwise the export would
        // miss the exact thing they were created for. Same order as in
        // the admin UI.
        $zusatz = $this->customFields->definitionen('contact', $this->mandantDesBenutzers());

        $kopf = [
            'Vorname', 'Nachname', 'Firma', 'Position', 'Abteilung', 'Hauptkontakt',
            'E-Mail', 'Telefon', 'Status', 'Herkunft',
            'Einwilligung erteilt am', 'Einwilligung widerrufen am',
            'Darf kontaktiert werden', 'Erfasst am',
        ];
        foreach ($zusatz as $feld) {
            $kopf[] = $feld->getLabel();
        }

        $zeilen = [];
        foreach ($qb->getQuery()->toIterable() as $k) {
            /** @var Contact $k */
            $zeilen[] = [
                $k->getFirstName(),
                $k->getLastName(),
                $k->getCompany()?->getName(),
                $k->getPosition(),
                $k->getDepartment(),
                $k->isPrimaryContact() ? 'Ja' : 'Nein',
                $k->getEmail(),
                $k->getPhone(),
                self::STATUS_LABEL[$k->getStatus()] ?? $k->getStatus(),
                self::SOURCE_LABEL[$k->getSource()] ?? $k->getSource(),
                $k->getConsentGivenAt()?->format('d.m.Y H:i'),
                $k->getConsentWithdrawnAt()?->format('d.m.Y H:i'),
                $k->isContactable() ? 'Ja' : 'Nein',
                $k->getCreatedAt()->format('d.m.Y H:i'),
            ];

            foreach ($zusatz as $feld) {
                $zeilen[array_key_last($zeilen)][] = $this->zusatzwert($k->getCustomData(), $feld);
            }
        }

        return [$kopf, $zeilen];
    }

    /**
     * @return array{0: string[], 1: array<int, array<int, string|null>>}
     */
    private function firmenDaten(Request $request): array
    {
        $qb = $this->em->getRepository(Company::class)->createQueryBuilder('f')
            ->orderBy('f.name', 'ASC');

        if ($name = $request->query->get('name')) {
            $qb->andWhere('f.name LIKE :name')->setParameter('name', '%' . $name . '%');
        }
        if ($city = $request->query->get('city')) {
            $qb->andWhere('f.city LIKE :city')->setParameter('city', '%' . $city . '%');
        }

        $kopf = ['Firmenname', 'Straße', 'PLZ', 'Ort', 'Website', 'Anzahl Ansprechpartner', 'Erfasst am'];

        $zeilen = [];
        foreach ($qb->getQuery()->toIterable() as $f) {
            /** @var Company $f */
            $zeilen[] = [
                $f->getName(),
                $f->getStreet(),
                $f->getZipCode(),
                $f->getCity(),
                $f->getWebsite(),
                (string) $f->getContacts()->count(),
                $f->getCreatedAt()->format('d.m.Y H:i'),
            ];
        }

        return [$kopf, $zeilen];
    }

    /**
     * @return array{0: string[], 1: array<int, array<int, string|null>>}
     */
    private function vorgaengeDaten(Request $request): array
    {
        $qb = $this->em->getRepository(Deal::class)->createQueryBuilder('d')
            ->leftJoin('d.contact', 'kt')->addSelect('kt')
            ->leftJoin('d.company', 'fi')->addSelect('fi')
            ->leftJoin('d.owner', 'ow')->addSelect('ow')
            ->orderBy('d.createdAt', 'DESC');

        if ($stage = $request->query->get('stage')) {
            // Filtered by the stage's id, no longer by a fixed key like
            // "gewonnen" as before. An old-style call would silently
            // become 0 via (int) and produce an empty but error-free-
            // looking file — for an export, a silent wrong result is
            // worse than an error.
            if (!ctype_digit((string) $stage)) {
                throw new BadRequestHttpException(
                    'Der Filter "stage" erwartet die Id einer Phase.'
                );
            }
            $qb->andWhere('d.stage = :stage')->setParameter('stage', (int) $stage);
        }
        if ($contactId = $request->query->get('contact')) {
            $qb->andWhere('kt.id = :contactId')->setParameter('contactId', $contactId);
        }
        if ($companyId = $request->query->get('company')) {
            $qb->andWhere('fi.id = :companyId')->setParameter('companyId', $companyId);
        }

        $kopf = [
            'Titel', 'Wert', 'Währung', 'Phase', 'Kontakt', 'Firma', 'Verantwortlicher',
            'Erwarteter Abschluss', 'Abgeschlossen am', 'Verlustgrund', 'Erfasst am',
        ];

        $zeilen = [];
        foreach ($qb->getQuery()->toIterable() as $d) {
            /** @var Deal $d */
            $verantwortlicher = $d->getOwner();
            $zeilen[] = [
                $d->getTitle(),
                $d->getValue(),
                $d->getCurrency(),
                $d->getStage()?->getName(),
                $d->getContact()?->getDisplayName(),
                $d->getCompany()?->getName(),
                $verantwortlicher instanceof User ? $verantwortlicher->getUsername() : null,
                $d->getExpectedCloseAt()?->format('d.m.Y'),
                $d->getClosedAt()?->format('d.m.Y H:i'),
                $d->getLostReason(),
                $d->getCreatedAt()->format('d.m.Y H:i'),
            ];
        }

        return [$kopf, $zeilen];
    }

    /**
     * CSV with a UTF-8 BOM and semicolon as the delimiter — without both,
     * Excel in German-speaking locales misreads the file (umlauts or
     * columns, respectively). `fputcsv` escapes values containing a
     * semicolon, quote, or line break automatically, as long as the
     * delimiter and enclosure characters are set explicitly.
     *
     * @param string[] $kopf
     * @param array<int, array<int, string|null>> $zeilen
     */
    private function csvAntwort(array $kopf, array $zeilen, string $dateiname): Response
    {
        $antwort = new StreamedResponse(function () use ($kopf, $zeilen): void {
            $ausgabe = fopen('php://output', 'w');
            fwrite($ausgabe, "\xEF\xBB\xBF");
            fputcsv($ausgabe, $kopf, ';', '"', '\\');
            foreach ($zeilen as $zeile) {
                fputcsv($ausgabe, array_map(static fn ($wert) => $wert ?? '', $zeile), ';', '"', '\\');
            }
            fclose($ausgabe);
        });

        $antwort->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $antwort->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $dateiname));

        return $antwort;
    }

    /**
     * XLSX via phpoffice/phpspreadsheet: bold header row, automatic
     * column width.
     *
     * @param string[] $kopf
     * @param array<int, array<int, string|null>> $zeilen
     */
    private function xlsxAntwort(array $kopf, array $zeilen, string $dateiname): Response
    {
        $tabelle = new Spreadsheet();
        $blatt = $tabelle->getActiveSheet();

        $blatt->fromArray($kopf, null, 'A1');
        $blatt->fromArray($zeilen, null, 'A2');

        $letzteSpalte = $blatt->getHighestColumn();
        $blatt->getStyle('A1:' . $letzteSpalte . '1')->getFont()->setBold(true);
        foreach (range('A', $letzteSpalte) as $spalte) {
            $blatt->getColumnDimension($spalte)->setAutoSize(true);
        }

        $antwort = new StreamedResponse(function () use ($tabelle): void {
            (new Xlsx($tabelle))->save('php://output');
        });

        $antwort->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $antwort->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $dateiname));

        return $antwort;
    }

    /** Tenant of the logged-in user — basis for the field definitions. */
    private function mandantDesBenutzers(): ?\App\Entity\Tenant
    {
        $benutzer = $this->getUser();

        return $benutzer instanceof \App\Entity\User ? $benutzer->getTenant() : null;
    }

    /** Make a custom value readable: Ja/Nein instead of true/false, empty instead of null. */
    private function zusatzwert(?array $daten, \App\Entity\CustomFieldDefinition $feld): ?string
    {
        $wert = $daten[$feld->getFieldKey()] ?? null;
        if ($wert === null || $wert === '') {
            return null;
        }

        if ($feld->getType() === 'janein') {
            return $wert ? 'Ja' : 'Nein';
        }

        return (string) $wert;
    }
}
