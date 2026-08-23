<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MailSetting;
use App\Service\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verschluesselt das im Klartext uebergebene Passwort, bevor Doctrine
 * speichert.
 *
 * Umgesetzt als State-Processor, nicht als kernel.view-Listener: API Platform
 * schreibt ueber Processors: ein Listener haengt sich an den falschen Punkt
 * und die Aenderung kam nie in der Datenbank an (im Test aufgefallen).
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
            // Leeres Feld laesst das bestehende Passwort unangetastet — sonst
            // wuerde das Speichern der uebrigen Felder es loeschen.
            if ($klartext !== null && $klartext !== '') {
                $data->setSecret($this->secretBox->encrypt($klartext));
                $data->setPlainSecret(null);
            }
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
