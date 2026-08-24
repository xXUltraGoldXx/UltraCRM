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
 * Equips every new tenant with a starter pipeline.
 *
 * Without it a freshly created tenant would have no stage at all and
 * could not create a single deal — an empty system that looks broken.
 * The tenant is free to change the stages afterwards.
 *
 * Deliberately a Doctrine listener rather than a state processor, so it
 * applies no matter how the tenant is created — including from the
 * console or in tests.
 *
 * Hooks into prePersist and does NOT call flush() itself. A flush() from
 * inside a listener would run in the middle of the ongoing UnitOfWork
 * commit; Doctrine already picks up newly scheduled entities in that same
 * pass on its own, so a manual flush here would be redundant and fragile.
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
            // addStage() also sets the inverse side: otherwise the
            // freshly created pipeline would not know its stages within
            // the same pass, and reading it back right after would show
            // an empty list.
            $pipeline->addStage($phase);
            $phase->setTenant($tenant);
            $em->persist($phase);
        }
    }
}
