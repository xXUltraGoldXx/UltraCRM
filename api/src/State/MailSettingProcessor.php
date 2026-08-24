<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MailSetting;
use App\Service\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the plaintext password before Doctrine persists it.
 *
 * Implemented as a state processor, not a kernel.view listener: API
 * Platform writes through processors, so a listener would hook into the
 * wrong point and the change would never reach the database.
 */
final class MailSettingProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $inner,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof MailSetting) {
            $klartext = $data->getPlainSecret();
            // An empty field leaves the existing password untouched —
            // otherwise saving the other fields would wipe it.
            if ($klartext !== null && $klartext !== '') {
                $data->setSecret($this->secretBox->encrypt($klartext));
                $data->setPlainSecret(null);
            }
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
