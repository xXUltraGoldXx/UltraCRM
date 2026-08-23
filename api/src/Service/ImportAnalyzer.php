<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Liest Import-Dateien (CSV/XLSX) und schlaegt eine Feldzuordnung vor.
 *
 * Die API ist zustandslos (siehe TODO.md A11): der Import laeuft in vier
 * Schritten, aber es gibt keinen Server-Zwischenspeicher — Kopfzeile und
 * Zuordnung wandern als Antwort zum Client und kommen beim Ausfuehren
 * zusammen mit der (ggf. korrigierten) Zuordnung wieder zurueck.
 */
final class ImportAnalyzer
{
    /** Obergrenzen aus TODO.md A11 (Risiko "grosse Dateien"). */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
    public const MAX_ROWS = 5000;

    /** Anzahl Vorschauzeilen fuer Schritt 1 (Kopfzeile + Zuordnungsvorschlag). */
    private const PREVIEW_ROWS = 5;

    /** Ziel-Felder im CRM, auf die eine Spalte abgebildet werden kann. */
    public const TARGET_FIELDS = ['firstName', 'lastName', 'email', 'phone', 'company', 'position', 'department'];

    /** Kein Zielfeld — Spalte wird beim Import nicht uebernommen. */
    public const IGNORE = 'ignore';

    /**
     * Synonyme, die nur exakt treffen duerfen. Als Teilstring wuerden sie
     * mehr kaputt machen als helfen ("name" in "Firmenname").
     */
    private const ZU_ALLGEMEIN = ['name', 'titel', 'title', 'rolle', 'mail', 'tel'];

    /**
     * Synonyme je Zielfeld (Rohschreibweisen, wie sie in fremden CRM-Exporten
     * vorkommen). Der Vergleich normalisiert beide Seiten (siehe normalize()):
     * klein geschrieben, ohne Sonderzeichen/Leerzeichen.
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
     * Liest die gesamte Datei (fuer Schritt 1 Vorschau und Schritt 3/4
     * Uebernahme dieselbe Grundlage). Wirft eine \RuntimeException mit
     * deutscher Meldung, wenn Groesse oder Zeilenzahl die Obergrenze
     * ueberschreiten oder die Datei nicht lesbar ist.
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
     * Vorschau fuer Schritt 1: Kopfzeile, die ersten Datenzeilen und ein
     * Zuordnungsvorschlag je Spalte.
     */
    public function analyze(UploadedFile $file): array
    {
        $data = $this->read($file);

        return [
            'headers' => $data['headers'],
            'previewRows' => array_slice($data['rows'], 0, self::PREVIEW_ROWS),
            'totalRows' => count($data['rows']),
            'suggestions' => $this->suggestMapping($data['headers']),
        ];
    }

    /**
     * Schlaegt fuer jede Spalte ein Zielfeld vor, indem die Ueberschrift
     * normalisiert gegen die Synonymtabelle verglichen wird. Unbekannte
     * Spalten bekommen den Vorschlag "ignorieren".
     *
     * @param string[] $headers
     * @return array<int, string>
     */
    public function suggestMapping(array $headers): array
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = [];
            foreach (self::SYNONYMS as $feld => $synonyme) {
                foreach ($synonyme as $synonym) {
                    $lookup[$this->normalize($synonym)] = $feld;
                }
                // Das Zielfeld selbst (z.B. "firstName") ebenfalls erkennen.
                $lookup[$this->normalize($feld)] = $feld;
            }
        }

        return array_map(
            function (string $header) use ($lookup): string {
                $norm = $this->normalize($header);
                if (isset($lookup[$norm])) {
                    return $lookup[$norm];
                }

                // Zweiter Versuch: Spaltennamen aus Fremdsystemen sind oft
                // zusammengesetzt ("Kontakt-Mail-Adresse", "Firmenname").
                // Deshalb zusaetzlich prüfen, ob ein bekanntes Synonym im
                // Namen steckt — laengste Treffer zuerst, damit "mail" nicht
                // gewinnt, wo "emailadresse" passt.
                $kandidaten = array_keys($lookup);
                usort($kandidaten, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                foreach ($kandidaten as $kandidat) {
                    // Zu allgemeine Woerter taugen nicht als Teilstring:
                    // "name" steckt auch in "Firmenname" und haette es
                    // faelschlich zum Nachnamen gemacht (im Test passiert).
                    // Eine falsche Zuordnung ist schlimmer als gar keine.
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
     * Vergleichbar machen: klein schreiben, deutsche Umlaute transliterieren,
     * alles ausser Buchstaben/Ziffern entfernen. So matchen "E-Mail",
     * "email" und "E-Mail-Adresse" auf denselben Schluessel — case-insensitiv
     * und ohne Sonderzeichen/Leerzeichen, wie in TODO.md A11 gefordert.
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

        // BOM entfernen — Excel-Exporte aus dem DACH-Raum schreiben oft eine.
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (trim($content) === '') {
            return [];
        }

        // Trennzeichen erkennen: Semikolon ist in DACH-Exporten ueblich,
        // andere Systeme liefern Komma oder Tab.
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
                continue; // leere Zeile
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
            // Komplett leere Zeilen ueberspringen (haeufig am Dateiende).
            if (count(array_filter($zeile, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => $v === null ? '' : trim((string) $v), $zeile);
        }

        return $rows;
    }
}
