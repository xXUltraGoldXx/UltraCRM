<?php

namespace App\Service;

use App\Entity\PermissionGroup;
use App\Entity\Tenant;
use App\Security\Permissions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The templates a tenant starts out with.
 *
 * Four levels were requested: read-only access, update-only, create but
 * not delete, and full access without admin rights — plus the separate
 * admin account itself. That gives four groups; the admin account is not
 * a group but a role, and stays unaffected by this.
 *
 * Explicitly TEMPLATES, not fixed roles: groups can be freely renamed
 * (e.g. "Intern") and configured per area. These four only exist so
 * nobody starts out with an empty list — they can be renamed, changed,
 * and deleted just like any group created from scratch.
 *
 * "Delete" in the privacy area is deliberately not part of any template:
 * it stands for the permanent deletion of a person under GDPR Art. 17,
 * which is meant to stay an admin-only permission for now, configurable
 * later if needed.
 */
final class Standardgruppen
{
    /** Day-to-day operational areas — excluding privacy and setup. */
    private const ALLTAG = ['contacts', 'deals', 'activities'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Creates missing templates. Safe to call multiple times: whatever
     * already exists (recognized by name) is left untouched — even if
     * someone has since changed its permissions.
     *
     * @return list<string> Names of the newly created groups
     */
    public function anlegen(Tenant $mandant): array
    {
        $vorhanden = [];
        foreach ($this->em->getRepository(PermissionGroup::class)->findBy(['tenant' => $mandant]) as $gruppe) {
            $vorhanden[mb_strtolower((string) $gruppe->getName())] = true;
        }

        $angelegt = [];
        foreach ($this->vorlagen() as $name => $rechte) {
            if (isset($vorhanden[mb_strtolower($name)])) {
                continue;
            }

            $gruppe = (new PermissionGroup())->setName($name)->setRechte($rechte);
            $gruppe->setTenant($mandant);
            $this->em->persist($gruppe);
            $angelegt[] = $name;
        }

        return $angelegt;
    }

    /** @return array<string, array<string, array<string, bool>>> */
    public function vorlagen(): array
    {
        return [
            'Nur Lesen' => $this->stufen(lesen: true),
            'Lesen und Ändern' => $this->stufen(lesen: true, schreiben: true),
            'Anlegen, nicht löschen' => $this->stufen(lesen: true, schreiben: true),
            'Voller Zugriff (kein Admin)' => $this->voll(),
        ];
    }

    /**
     * The day-to-day areas with the same levels, plus read access to
     * reports.
     *
     * "Lesen und Ändern" ("read and update") and "Anlegen, nicht löschen"
     * ("create, not delete") are technically identical: creating and
     * updating are the same level in the system (write). Both templates
     * are kept as separate options anyway, named for what matters day to
     * day: what is NOT included, namely deleting.
     *
     * @return array<string, array<string, bool>>
     */
    private function stufen(bool $lesen = false, bool $schreiben = false): array
    {
        $rechte = [];
        foreach (self::ALLTAG as $bereich) {
            $rechte[$bereich] = array_filter([
                'lesen' => $lesen,
                'schreiben' => $schreiben,
            ]);
        }
        $rechte['reports'] = ['lesen' => true];

        return $rechte;
    }

    /**
     * Everything that can be granted without admin rights — including
     * delete in day-to-day operations, but not privacy deletion.
     *
     * @return array<string, array<string, bool>>
     */
    private function voll(): array
    {
        $rechte = [];
        foreach (Permissions::BEREICHE as $bereich => $stufen) {
            foreach ($stufen as $stufe) {
                if ($bereich === 'privacy' && $stufe === 'loeschen') {
                    continue;
                }
                $rechte[$bereich][$stufe] = true;
            }
        }

        return $rechte;
    }
}
