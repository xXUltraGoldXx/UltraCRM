<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Stage;
use App\Service\MandantReferenz;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Eine Phase darf nur in einer Pipeline des eigenen Mandanten liegen. */
final class StageProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $inner,
        private readonly MandantReferenz $mandantReferenz,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Stage) {
            $this->mandantReferenz->pruefe($data, $data->getPipeline(), 'Pipeline');
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
