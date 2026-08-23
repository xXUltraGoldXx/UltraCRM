<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Appointment;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Setzt beim Anlegen automatisch den Ersteller -- analog SubmissionProcessor.
 * createdBy steht bewusst nur in der read-Gruppe (siehe Appointment-Entity),
 * ein Client kann es also gar nicht mitschicken; dieser Processor ist die
 * einzige Stelle, die es setzt.
 *
 * @implements ProcessorInterface<Appointment, Appointment|void>
 */
final class AppointmentProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Appointment && !$data->getCreatedBy()) {
            $user = $this->security->getUser();
            if ($user instanceof \App\Entity\User) {
                $data->setCreatedBy($user);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
