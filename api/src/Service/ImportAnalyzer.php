<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reads import files (CSV/XLSX) and suggests a field mapping.
 *
 * The API is stateless: the import runs in four steps, but there is no
 * server-side cache — the header row and the mapping travel to the client
 * as part of the response, and come back together with the (possibly
 * corrected) mapping when the import is executed.
 */
final class ImportAnalyzer
{
    /** Upper limits to guard against oversized import files. */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
    public const MAX_ROWS = 5000;

    /** Number of preview rows for step 1 (header + suggested mapping). */
    private const PREVIEW_ROWS = 5;

    /** Target fields in the CRM that a column can be mapped to. */
    public const TARGET_FIELDS = ['firstName', 'lastName', 'email', 'phone', 'company', 'position', 'department'];

    /** Not a target field — the column is skipped during import. */
    public const IGNORE = 'ignore';

    /**
     * Synonyms that may only match exactly. As a substring they would
     * cause more harm than good (e.g. "name" inside "Firmenname").
     */
    private const ZU_ALLGEMEIN = ['name', 'titel', 'title', 'rolle', 'mail', 'tel'];

    /**
     * Synonyms per target field (raw spellings as they appear in exports
     * from other CRMs). The comparison normalizes both sides (see
     * normalize()): lowercased, without special characters or spaces.
     */
    private const SYNONYMS = [
        'firstName' => ['vorname', 'first name', 'firstname', 'given name', 'rufname'],
        'lastName' => ['nachname', 'name', 'last name', 'surname', 'familienname'],
        'email' => [
            'e-mail', 'email', 'mail', 'e-mail-adresse', 'mail-adresse', 'mailadresse',
            'mail address', 'email address', 'emailadresse', 'e mail', 'kontakt-mail',
        ],
        'phone' => ['telefon', 'tel', 'phone', 'telefonnummer', 'mobil'],
        'company' => ['firma', 'firmenname', 'unternehmen', 'company', 'company name', 'organisation', 'organization', 'account'],
        'position' => ['position', 'funktion', 'titel', 'job title', 'rolle'],
        'department' => ['abteilung', 'department', 'bereich'],
    ];

    /**
     * Reads the whole file (used as the same basis for both the step 1
     * preview and the step 3/4 import). Throws a \RuntimeException with a
     * German message if the size or row count exceeds the limit, or if
     * the file can't be read.
     *
     * @return array{headers: string[], rows: array<int, array<int, string>>}
     */
    public function read(UploadedFile $file): array
    {
        if ($file->getSize() !== null && $file->getSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('Die Datei ist zu groß (maximal 5 MB).');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
        $rows = in_array($extension, ['xlsx', 'xls'], true) ? $this->readXlsx($file) : $this->readCsv($file);

        if (count($rows) === 0) {
            throw new \RuntimeException('Die Datei enthält keine Kopfzeile.');
        }

        $headers = array_map(static fn ($h) => trim((string) $h), array_shift($rows));

        if (count($rows) > self::MAX_ROWS) {
            throw new \RuntimeException(sprintf('Die Datei hat zu viele Zeilen (maximal %d).', self::MAX_ROWS));
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Preview for step 1: header row, the first data rows, and a
     * suggested mapping per column.
     */
    public function analyze(UploadedFile $file, array $zusatzfelder = []): array
    {
        $data = $this->read($file);

        return [
            'headers' => $data['headers'],
            'previewRows' => array_slice($data['rows'], 0, self::PREVIEW_ROWS),
            'totalRows' => count($data['rows']),
            'suggestions' => $this->suggestMapping($data['headers'], $zusatzfelder),
        ];
    }

    /**
     * Suggests a target field for each column by comparing the
     * normalized header against the synonym table. Unknown columns get
     * the "ignore" suggestion.
     *
     * @param string[] $headers
     * @return array<int, string>
     */
    public function suggestMapping(array $headers, array $zusatzfelder = []): array
    {
        // No static cache anymore: custom fields differ per tenant, so a
        // lookup table built once would be wrong for the next tenant.
        $lookup = null;
        if ($lookup === null) {
            $lookup = [];
            foreach (self::SYNONYMS as $feld => $synonyme) {
                foreach ($synonyme as $synonym) {
                    $lookup[$this->normalize($synonym)] = $feld;
                }
                // Also recognize the target field name itself (e.g. "firstName").
                $lookup[$this->normalize($feld)] = $feld;
            }

            // Custom fields: recognize both the label AND the key, so a
            // column "Kundennummer" matches just as well as "kundennummer".
            foreach ($zusatzfelder as $zf) {
                $lookup[$this->normalize($zf->getLabel())] = 'custom.' . $zf->getFieldKey();
                $lookup[$this->normalize($zf->getFieldKey())] = 'custom.' . $zf->getFieldKey();
            }
        }

        return array_map(
            function (string $header) use ($lookup): string {
                $norm = $this->normalize($header);
                if (isset($lookup[$norm])) {
                    return $lookup[$norm];
                }

                // Second attempt: column names from other systems are
                // often compound ("Kontakt-Mail-Adresse", "Firmenname").
                // So also check whether a known synonym is contained in
                // the name — longest match first, so "mail" doesn't win
                // where "emailadresse" fits.
                $kandidaten = array_keys($lookup);
                usort($kandidaten, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                foreach ($kandidaten as $kandidat) {
                    // Overly generic words don't work as a substring
                    // match: "name" is also contained in "Firmenname" and
                    // would incorrectly map it to last name (this
                    // happened in a test). A wrong mapping is worse than
                    // none at all.
                    if (in_array($kandidat, self::ZU_ALLGEMEIN, true)) {
                        continue;
                    }

                    if (mb_strlen($kandidat) >= 4 && str_contains($norm, $kandidat)) {
                        return $lookup[$kandidat];
                    }
                }

                return self::IGNORE;
            },
            $headers,
        );
    }

    /**
     * Makes values comparable: lowercases, transliterates German umlauts,
     * strips everything except letters and digits. This way "E-Mail",
     * "email" and "E-Mail-Adresse" all match the same key — case-
     * insensitive and without special characters or spaces.
     */
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'à' => 'a', 'ê' => 'e',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }

    /** @return array<int, array<int, string>> */
    private function readCsv(UploadedFile $file): array
    {
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            throw new \RuntimeException('Die Datei konnte nicht gelesen werden.');
        }

        // Strip BOM — Excel exports from German-speaking countries often add one.
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (trim($content) === '') {
            return [];
        }

        // Detect the delimiter: semicolon is common in German-region
        // exports, other systems produce comma or tab.
        $ersteZeile = strtok($content, "\r\n") ?: '';
        $delimiter = ';';
        $treffer = 0;
        foreach ([';', ',', "\t"] as $kandidat) {
            $anzahl = substr_count($ersteZeile, $kandidat);
            if ($anzahl > $treffer) {
                $treffer = $anzahl;
                $delimiter = $kandidat;
            }
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($zeile = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($zeile === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => $v === null ? '' : (string) $v, $zeile);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function readXlsx(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Die Excel-Datei konnte nicht gelesen werden.');
        }

        $rows = [];
        foreach ($spreadsheet->getActiveSheet()->toArray(null, true, true, false) as $zeile) {
            // Skip fully empty rows (common at the end of the file).
            if (count(array_filter($zeile, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => $v === null ? '' : trim((string) $v), $zeile);
        }

        return $rows;
    }
}
