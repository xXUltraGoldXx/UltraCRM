<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds probable duplicates in the contact list.
 *
 * Two levels, deliberately kept separate:
 * - confirmed: same email. One address belongs to exactly one person.
 * - possible:  same first and last name at the same company. That may or
 *              may not be the same person (father and son at the same
 *              company) — so this is never merged automatically, only
 *              suggested.
 *
 * The comparison runs through the tenant filter; nothing is ever compared
 * across tenant boundaries.
 */
final class DuplicateFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{sicherheit: string, grund: string, kontakte: list<Contact>}>
     */
    public function finden(?Tenant $tenant): array
    {
        /** @var Contact[] $alle */
        $alle = $this->em->getRepository(Contact::class)->findBy(
            $tenant !== null ? ['tenant' => $tenant] : [],
            ['lastName' => 'ASC'],
        );

        $gruppen = [];

        // Level 1: same email
        $jeEmail = [];
        foreach ($alle as $k) {
            $mail = mb_strtolower(trim((string) $k->getEmail()));
            if ($mail !== '') {
                $jeEmail[$mail][] = $k;
            }
        }
        foreach ($jeEmail as $mail => $kontakte) {
            if (count($kontakte) > 1) {
                $gruppen[] = [
                    'sicherheit' => 'sicher',
                    'grund' => sprintf('Gleiche E-Mail-Adresse: %s', $mail),
                    'kontakte' => $kontakte,
                ];
            }
        }

        // Level 2: same name at the same company
        $schonGemeldet = [];
        foreach ($gruppen as $g) {
            foreach ($g['kontakte'] as $k) {
                $schonGemeldet[$k->getId()] = true;
            }
        }

        $jeName = [];
        foreach ($alle as $k) {
            if (isset($schonGemeldet[$k->getId()])) {
                continue;
            }

            // Without a company, matching names aren't a useful signal:
            // two "Michael Schmidt" entries with no company are usually
            // two different people. Flagging such pairs here would be the
            // most common false positive.
            if ($k->getCompany() === null || trim($k->getDisplayName()) === '') {
                continue;
            }

            $schluessel = mb_strtolower(trim($k->getDisplayName())) . '|' . $k->getCompany()->getId();
            $jeName[$schluessel][] = $k;
        }
        foreach ($jeName as $kontakte) {
            if (count($kontakte) > 1) {
                $gruppen[] = [
                    'sicherheit' => 'moeglich',
                    'grund' => sprintf(
                        'Gleicher Name in derselben Firma: %s',
                        $kontakte[0]->getCompany()->getName()
                    ),
                    'kontakte' => $kontakte,
                ];
            }
        }

        return $gruppen;
    }
}
