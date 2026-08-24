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
 * Verhindert, dass eine Phase oder Pipeline geloescht wird, an der noch
 * Vorgaenge haengen.
 *
 * Die Fremdschluessel stehen auf RESTRICT, die Daten waren also nie in
 * Gefahr — der Aufrufer bekam aber einen HTTP 500 und keinen Hinweis,
 * was zu tun ist. Hier wird daraus eine Antwort, mit der jemand arbeiten
 * kann: erst die Vorgaenge umhaengen, dann loeschen.
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
            // Die letzte Pipeline muss bleiben. Ohne sie hat der Mandant
            // keine einzige Phase mehr, und da eine Phase am Vorgang Pflicht
            // ist, liesse sich kein neuer Vorgang mehr anlegen — dasselbe
            // kaputt wirkende System, das TenantPipelineListener verhindert.
            $verbleibend = (int) $this->em->getRepository(Pipeline::class)
                ->count(['tenant' => $data->getTenant()]);
            if ($verbleibend <= 1) {
                throw new ConflictHttpException(
                    'Die letzte Pipeline kann nicht geloescht werden. Lege zuerst eine weitere an.'
                );
            }

            // Erst pruefen, ob ueberhaupt geloescht werden darf ...
            foreach ($data->getStages() as $phase) {
                $this->pruefePhase($phase, $data->getName());
            }

            // ... dann die Phasen mitnehmen. Der Fremdschluessel steht auf
            // RESTRICT, ohne diesen Schritt scheitert das Loeschen einer
            // Pipeline mit Phasen an der Datenbank (HTTP 500) — und die
            // Oberflaeche sagt dem Anwender ausdruecklich zu, dass die
            // Phasen mitgehen.
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
