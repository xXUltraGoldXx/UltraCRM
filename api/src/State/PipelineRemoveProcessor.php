<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Deal;
use App\Entity\Pipeline;
use App\Entity\Stage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Prevents a stage or pipeline from being deleted while deals still
 * reference it.
 *
 * The foreign keys are set to RESTRICT, so the data was never actually
 * at risk — but the caller got an HTTP 500 with no hint what to do
 * about it. This turns that into a response people can act on: move
 * the deals first, then delete.
 */
final class PipelineRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $inner,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Stage) {
            $this->pruefePhase($data);
        }

        if ($data instanceof Pipeline) {
            // The last pipeline must stay. Without it the tenant would
            // have no stage at all, and since a deal requires a stage,
            // no new deal could be created — the same broken-looking
            // system that TenantPipelineListener prevents.
            $verbleibend = (int) $this->em->getRepository(Pipeline::class)
                ->count(['tenant' => $data->getTenant()]);
            if ($verbleibend <= 1) {
                throw new ConflictHttpException(
                    'Die letzte Pipeline kann nicht geloescht werden. Lege zuerst eine weitere an.'
                );
            }

            // First check whether deletion is allowed at all ...
            foreach ($data->getStages() as $phase) {
                $this->pruefePhase($phase, $data->getName());
            }

            // ... then take the stages down with it. The foreign key is
            // set to RESTRICT, so without this step deleting a pipeline
            // that still has stages would fail at the database (HTTP
            // 500) — and the frontend explicitly promises the user that
            // the stages go with it.
            foreach ($data->getStages() as $phase) {
                $this->em->remove($phase);
            }
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }

    private function pruefePhase(Stage $phase, ?string $pipelineName = null): void
    {
        $anzahl = (int) $this->em->getRepository(Deal::class)
            ->count(['stage' => $phase]);

        if ($anzahl === 0) {
            return;
        }

        $vorgaenge = $anzahl === 1 ? 'ein Vorgang' : sprintf('%d Vorgaenge', $anzahl);

        throw new ConflictHttpException(sprintf(
            $pipelineName !== null
                ? 'Die Pipeline "%2$s" kann nicht geloescht werden: in der Phase "%1$s" liegt noch %3$s. Bitte zuerst umhaengen.'
                : 'Die Phase "%1$s" kann nicht geloescht werden: es liegt noch %3$s darin. Bitte zuerst umhaengen.',
            $phase->getName(),
            $pipelineName ?? '',
            $vorgaenge,
        ));
    }
}
