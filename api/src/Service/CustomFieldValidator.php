<?php

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prueft die Werte von Zusatzfeldern gegen ihre Definition.
 *
 * Ein JSON-Feld ohne Pruefung nimmt alles an — auch Zahlen im Datumsfeld
 * oder Auswahlwerte, die es gar nicht gibt. Spaetestens beim Auswerten faellt
 * das auf die Fuesse.
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
     * Prueft und normalisiert. Gibt Fehlermeldungen je Feldschluessel zurueck;
     * ist die Liste leer, sind die Werte in Ordnung.
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
                    // Text: Laenge begrenzen, damit niemand ein Buch ablegt.
                    $sauber[$d->getFieldKey()] = mb_substr((string) $wert, 0, 2000);
            }
        }

        // Werte ohne Definition verschwinden stillschweigend — sie gehoeren
        // nicht zu diesem Mandanten und haetten sonst keinerlei Bedeutung.
        foreach (array_keys($werte) as $schluessel) {
            if (!in_array($schluessel, $bekannt, true)) {
                unset($sauber[$schluessel]);
            }
        }

        return ['werte' => $sauber, 'fehler' => $fehler];
    }
}
