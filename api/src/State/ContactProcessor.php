<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Haelt die Regel "genau ein Hauptansprechpartner je Firma" serverseitig.
 *
 * Vorher stand die Regel nur im Umsetzen-Knopf der Oberflaeche: wer eine
 * Person MIT Haken anlegte, bekam einen zweiten Hauptkontakt, und mehrere
 * parallele PATCHes konnten den Stand zerreissen (Review-Befunde 72 und 48).
 * Eine Invariante gehoert an die Stelle, die niemand umgehen kann.
 */
final class ContactProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $inner,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Contact || !$data->isPrimaryContact() || $data->getCompany() === null) {
            return $this->inner->process($data, $operation, $uriVariables, $context);
        }

        // Alles in einer Transaktion: entweder wechselt der Hauptkontakt
        // vollstaendig, oder gar nicht.
        return $this->em->wrapInTransaction(function () use ($data, $operation, $uriVariables, $context) {
            $qb = $this->em->createQueryBuilder()
                ->update(Contact::class, 'c')
                ->set('c.primaryContact', ':aus')
                ->where('c.company = :firma')
                ->andWhere('c.primaryContact = :an')
                ->setParameter('aus', false)
                ->setParameter('an', true)
                ->setParameter('firma', $data->getCompany());

            // Beim Aendern eines bestehenden Kontakts sich selbst aussparen.
            if ($data->getId() !== null) {
                $qb->andWhere('c.id != :selbst')->setParameter('selbst', $data->getId());
            }

            $qb->getQuery()->execute();

            return $this->inner->process($data, $operation, $uriVariables, $context);
        });
    }
}
