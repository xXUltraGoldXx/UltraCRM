<?php

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validates custom field values against their definitions.
 *
 * An unvalidated JSON field accepts anything — numbers in a date field,
 * select options that don't even exist. That comes back to bite you at
 * the latest when the data gets reported on.
 */
final class CustomFieldValidator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<CustomFieldDefinition> */
    public function definitionen(string $entityType, ?Tenant $tenant): array
    {
        return $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['entityType' => $entityType, 'tenant' => $tenant], ['position' => 'ASC']);
    }

    /**
     * Validates and normalizes. Returns error messages per field key; an
     * empty list means the values are fine.
     *
     * @return array{werte: array<string, mixed>, fehler: array<string, string>}
     */
    public function pruefen(array $werte, string $entityType, ?Tenant $tenant): array
    {
        $fehler = [];
        $sauber = [];

        $definitionen = $this->definitionen($entityType, $tenant);
        $bekannt = [];

        foreach ($definitionen as $d) {
            $bekannt[] = $d->getFieldKey();
            $wert = $werte[$d->getFieldKey()] ?? null;

            if ($wert === null || $wert === '') {
                if ($d->isRequired()) {
                    $fehler[$d->getFieldKey()] = sprintf('„%s" ist ein Pflichtfeld.', $d->getLabel());
                }

                continue;
            }

            switch ($d->getType()) {
                case 'zahl':
                    if (!is_numeric($wert)) {
                        $fehler[$d->getFieldKey()] = sprintf('„%s" erwartet eine Zahl.', $d->getLabel());
                        continue 2;
                    }
                    $sauber[$d->getFieldKey()] = $wert + 0;
                    break;

                case 'datum':
                    $datum = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $wert);
                    if ($datum === false) {
                        $fehler[$d->getFieldKey()] = sprintf('„%s" erwartet ein Datum (JJJJ-MM-TT).', $d->getLabel());
                        continue 2;
                    }
                    $sauber[$d->getFieldKey()] = $datum->format('Y-m-d');
                    break;

                case 'auswahl':
                    if (!in_array($wert, $d->getOptions() ?? [], true)) {
                        $fehler[$d->getFieldKey()] = sprintf(
                            '„%s" erlaubt nur: %s.',
                            $d->getLabel(),
                            implode(', ', $d->getOptions() ?? [])
                        );
                        continue 2;
                    }
                    $sauber[$d->getFieldKey()] = $wert;
                    break;

                case 'janein':
                    $sauber[$d->getFieldKey()] = (bool) $wert;
                    break;

                default:
                    // Text: cap the length so nobody stores a novel in it.
                    $sauber[$d->getFieldKey()] = mb_substr((string) $wert, 0, 2000);
            }
        }

        // Values without a definition silently disappear — they don't
        // belong to this tenant and would otherwise have no meaning at
        // all.
        foreach (array_keys($werte) as $schluessel) {
            if (!in_array($schluessel, $bekannt, true)) {
                unset($sauber[$schluessel]);
            }
        }

        return ['werte' => $sauber, 'fehler' => $fehler];
    }
}
