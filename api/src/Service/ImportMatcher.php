<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Looks up contacts that may already exist for an import row.
 *
 * Previously, the import silently skipped anything that already existed
 * by email — the row from the file was then lost, even though it often
 * contained newer data. The import needs to offer a choice instead:
 * update the existing contact where a match is found, or explicitly
 * create a new one.
 *
 * The levels match DuplicateFinder's, so "duplicate" means the same thing
 * throughout the system:
 * - confirmed: same email (case-insensitive)
 * - possible:  same name at the same company
 * - possible:  same name, both without a company
 *
 * DuplicateFinder deliberately does NOT run that last level (there,
 * same-named people with no company would be almost all false
 * positives). During import the situation is different: a human decides
 * row by row before anything happens — one hint too many just costs a
 * glance, one hint too few creates a duplicate record.
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
     * Loads the existing contacts once per import — not once per row.
     * With 500 rows that would otherwise mean 500 queries.
     */
    public function vorbereiten(?Tenant $tenant): void
    {
        $this->bestand = $this->em->getRepository(Contact::class)->findBy(['tenant' => $tenant]);
        $this->imLauf = [];
    }

    /**
     * Takes a contact freshly built from the row and returns matching
     * existing contacts, best match first.
     *
     * @param string|null $firmenName Company name from the file — the
     *        company doesn't exist as an entity yet at this point, hence
     *        a name instead of an object.
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

        // Confirmed matches first: the UI shows the first one as its
        // suggestion, and "same email" is the most reliable signal.
        return array_merge($sicher, $moeglich);
    }

    /**
     * Remembers a row from the same file that has already been checked.
     *
     * Without this, a file containing the same person twice (common in
     * exported lists) would find no match — the preview would suggest
     * "new" for both rows and the import would create two contacts.
     * Before the preview step existed, the email uniqueness constraint in
     * execute() prevented this; with explicit per-row decisions that
     * safety net is gone, so the duplicate has to be caught here instead.
     */
    public function merken(int $zeilennummer, Contact $kontakt, ?string $firmenName): void
    {
        $this->imLauf[] = ['row' => $zeilennummer, 'kontakt' => $kontakt, 'firma' => $firmenName];
    }

    /**
     * Row number of an earlier row in the same file referring to the
     * same person — or null.
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
