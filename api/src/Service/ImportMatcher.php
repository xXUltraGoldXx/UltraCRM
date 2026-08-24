<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sucht zu einer Importzeile bereits vorhandene Kontakte.
 *
 * Bisher hat der Import stillschweigend uebersprungen, was per E-Mail schon
 * existierte — der Datensatz aus der Datei war damit verloren, obwohl er oft
 * neuere Angaben enthaelt (Alexander, 24.08.: "da muss dann beim import
 * kommen wo möglich Kunde existiert schon ergänzen/updaten? Oder auswahl
 * ist neu kunde anlegen").
 *
 * Die Stufen entsprechen denen von DuplicateFinder, damit "Dublette" im
 * ganzen System dasselbe bedeutet:
 * - sicher:   gleiche E-Mail (Gross-/Kleinschreibung egal)
 * - moeglich: gleicher Name in derselben Firma
 * - moeglich: gleicher Name, beide ohne Firma
 *
 * Die letzte Stufe fuehrt DuplicateFinder bewusst NICHT (dort waeren
 * gleichnamige Personen ohne Firmenbezug fast nur Fehltreffer, siehe
 * Analyse.md C20). Beim Import ist die Lage anders: hier entscheidet ein
 * Mensch Zeile fuer Zeile, bevor etwas passiert — ein Hinweis zu viel kostet
 * einen Blick, ein Hinweis zu wenig erzeugt einen doppelten Datensatz.
 */
final class ImportMatcher
{
    public const SICHER = 'sicher';
    public const MOEGLICH = 'moeglich';

    /** @var array<int, Contact>|null Kontakte des Mandanten, einmal geladen */
    private ?array $bestand = null;

    /** @var list<array{row: int, kontakt: Contact, firma: string|null}> Zeilen derselben Datei */
    private array $imLauf = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Laedt den Bestand einmal je Import — nicht je Zeile. Bei 500 Zeilen
     * waeren das sonst 500 Abfragen.
     */
    public function vorbereiten(?Tenant $tenant): void
    {
        $this->bestand = $this->em->getRepository(Contact::class)->findBy(['tenant' => $tenant]);
        $this->imLauf = [];
    }

    /**
     * Nimmt einen frisch aus der Zeile gebauten Kontakt entgegen und liefert
     * passende Bestandskontakte, beste Uebereinstimmung zuerst.
     *
     * @param string|null $firmenName Firma aus der Datei — die Firma ist zu
     *        diesem Zeitpunkt noch nicht angelegt, deshalb der Name statt
     *        eines Objekts.
     *
     * @return list<array{kontakt: Contact, sicherheit: string, grund: string}>
     */
    public function treffer(Contact $neu, ?string $firmenName): array
    {
        if ($this->bestand === null) {
            throw new \LogicException('vorbereiten() muss vor treffer() aufgerufen werden.');
        }

        $email = $neu->getEmail() !== null ? mb_strtolower(trim($neu->getEmail())) : null;
        $name = mb_strtolower(trim($neu->getDisplayName()));
        $firma = $firmenName !== null ? mb_strtolower(trim($firmenName)) : null;

        $sicher = [];
        $moeglich = [];

        foreach ($this->bestand as $vorhanden) {
            $vorhandeneMail = $vorhanden->getEmail() !== null
                ? mb_strtolower(trim($vorhanden->getEmail()))
                : null;

            if ($email !== null && $vorhandeneMail === $email) {
                $sicher[] = [
                    'kontakt' => $vorhanden,
                    'sicherheit' => self::SICHER,
                    'grund' => sprintf('Gleiche E-Mail-Adresse: %s', $vorhanden->getEmail()),
                ];
                continue;
            }

            if ($name === '' || mb_strtolower(trim($vorhanden->getDisplayName())) !== $name) {
                continue;
            }

            $vorhandeneFirma = $vorhanden->getCompany()?->getName();
            $vorhandeneFirma = $vorhandeneFirma !== null ? mb_strtolower(trim($vorhandeneFirma)) : null;

            if ($firma !== null && $vorhandeneFirma === $firma) {
                $moeglich[] = [
                    'kontakt' => $vorhanden,
                    'sicherheit' => self::MOEGLICH,
                    'grund' => sprintf('Gleicher Name in derselben Firma: %s', $vorhanden->getCompany()?->getName()),
                ];
                continue;
            }

            if ($firma === null && $vorhandeneFirma === null) {
                $moeglich[] = [
                    'kontakt' => $vorhanden,
                    'sicherheit' => self::MOEGLICH,
                    'grund' => 'Gleicher Name, beide ohne Firmenzuordnung',
                ];
            }
        }

        // Sichere Treffer zuerst: die Oberflaeche zeigt den ersten als
        // Vorschlag an, und "gleiche E-Mail" ist der belastbarste Hinweis.
        return array_merge($sicher, $moeglich);
    }

    /**
     * Merkt sich eine bereits geprüfte Zeile derselben Datei.
     *
     * Ohne das faende eine Datei, die denselben Menschen zweimal enthaelt
     * (in Exportlisten haeufig), keinen Treffer — die Vorschau schluege bei
     * beiden Zeilen "neu" vor und der Import legte zwei Kontakte an. Vor
     * dem Vorschau-Schritt hat das die E-Mail-Sperre in execute() verhindert;
     * mit ausdruecklichen Entscheidungen faellt die weg, also muss die
     * Doppelung hier auffallen.
     */
    public function merken(int $zeilennummer, Contact $kontakt, ?string $firmenName): void
    {
        $this->imLauf[] = ['row' => $zeilennummer, 'kontakt' => $kontakt, 'firma' => $firmenName];
    }

    /**
     * Zeilennummer einer frueheren Zeile derselben Datei, die denselben
     * Menschen meint — oder null.
     */
    public function dateiDublette(Contact $neu, ?string $firmenName): ?int
    {
        $email = $neu->getEmail() !== null ? mb_strtolower(trim($neu->getEmail())) : null;
        $name = mb_strtolower(trim($neu->getDisplayName()));
        $firma = $firmenName !== null ? mb_strtolower(trim($firmenName)) : null;

        foreach ($this->imLauf as $frueher) {
            $frueherMail = $frueher['kontakt']->getEmail() !== null
                ? mb_strtolower(trim($frueher['kontakt']->getEmail()))
                : null;

            if ($email !== null && $frueherMail === $email) {
                return $frueher['row'];
            }

            if ($name === '' || mb_strtolower(trim($frueher['kontakt']->getDisplayName())) !== $name) {
                continue;
            }

            $frueherFirma = $frueher['firma'] !== null ? mb_strtolower(trim($frueher['firma'])) : null;
            if ($frueherFirma === $firma) {
                return $frueher['row'];
            }
        }

        return null;
    }
}
