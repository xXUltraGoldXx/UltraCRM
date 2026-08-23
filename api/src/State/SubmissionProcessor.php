<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\FormSubmission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Setzt beim Speichern automatisch den Ersteller und einen Schnappschuss
 * des Vorlagennamens.
 *
 * @implements ProcessorInterface<FormSubmission, FormSubmission|void>
 */
final class SubmissionProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof FormSubmission) {
            if (!$data->getCreatedBy()) {
                $user = $this->security->getUser();
                if ($user instanceof \App\Entity\User) {
                    $data->setCreatedBy($user);
                }
            }
            if ($data->getTemplate()) {
                $data->setTemplateName($data->getTemplate()->getName());
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
