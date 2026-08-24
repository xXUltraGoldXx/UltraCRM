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
 * Validates the custom fields of companies and deals.
 *
 * For contacts, the same validation lives in ContactProcessor, because
 * that one also enforces the primary-contact rule. Here validation alone
 * is enough.
 *
 * As there: on creation the record has no tenant yet (the
 * TenantAssignListener only sets it afterwards), hence the fallback to
 * the logged-in user's tenant.
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
