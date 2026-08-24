<?php

namespace App\Doctrine;

use App\Entity\Deal;
use App\Entity\Pipeline;
use App\Entity\Stage;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Stattet jeden neuen Mandanten mit einer Startpipeline aus.
 *
 * Ohne sie haette ein frisch angelegter Mandant keine einzige Phase und
 * koennte keinen Vorgang anlegen — ein leeres System, das aussieht, als
 * waere es kaputt. Der Mandant kann die Phasen danach frei aendern.
 *
 * Bewusst als Doctrine-Listener und nicht als State-Processor: so greift es
 * auf jedem Weg, auch beim Anlegen ueber die Konsole oder in Tests.
 *
 * Haengt an prePersist und flusht NICHT selbst. Ein flush() aus einem
 * Listener heraus laeuft mitten im Commit des laufenden UnitOfWork; Doctrine
 * nimmt hier neu angemeldete Datensaetze von sich aus im selben Durchgang
 * mit. (Review-Befund, Schwere 70 — der vorhergesagte Schaden liess sich in
 * der Probe zwar nicht ausloesen, das Muster bleibt aber unnoetig fragil.)
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantPipelineListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $tenant = $args->getObject();
        if (!$tenant instanceof Tenant) {
            return;
        }

        $em = $args->getObjectManager();

        $pipeline = (new Pipeline())->setName('Vertrieb')->setPosition(0);
        $pipeline->setTenant($tenant);
        $em->persist($pipeline);

        $position = 0;
        foreach (Deal::START_PHASEN as $name => $art) {
            $phase = (new Stage())
                ->setName($name)
                ->setArt($art)
                ->setPosition($position++);
            // addStage() setzt die Rueckrichtung mit: sonst kennt die frisch
            // angelegte Pipeline ihre Phasen im selben Durchgang nicht, und
            // wer sie direkt danach ausliest, sieht eine leere Liste.
            $pipeline->addStage($phase);
            $phase->setTenant($tenant);
            $em->persist($phase);
        }
    }
}
