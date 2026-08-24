<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Company;
use App\Entity\Deal;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use App\Service\CustomFieldValidator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prueft die Zusatzfelder von Firmen und Vorgaengen.
 *
 * Beim Kontakt sitzt dieselbe Pruefung im ContactProcessor, weil der
 * zusaetzlich die Hauptkontakt-Regel haelt. Hier reicht die Pruefung allein.
 *
 * Wie dort gilt: Beim Anlegen hat der Datensatz noch keinen Mandanten (den
 * setzt der TenantAssignListener erst danach), deshalb der Rueckgriff auf
 * den angemeldeten Benutzer — siehe Analyse.md C14.
 */
final class CustomDataProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $inner,
        private readonly CustomFieldValidator $customFields,
        private readonly Security $security,
        private readonly \App\Service\MandantReferenz $mandantReferenz,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $typ = match (true) {
            $data instanceof Company => 'company',
            $data instanceof Deal => 'deal',
            default => null,
        };

        if ($data instanceof Deal) {
            $this->mandantReferenz->pruefe($data, $data->getStage(), 'Phase');
        }

        if ($typ !== null && $data->getCustomData() !== null) {
            $benutzer = $this->security->getUser();
            $mandant = ($data instanceof TenantOwnedInterface ? $data->getTenant() : null)
                ?? ($benutzer instanceof User ? $benutzer->getTenant() : null);

            $ergebnis = $this->customFields->pruefen($data->getCustomData(), $typ, $mandant);
            if ($ergebnis['fehler'] !== []) {
                throw new ValidationException(implode(' ', $ergebnis['fehler']));
            }

            $data->setCustomData($ergebnis['werte']);
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
