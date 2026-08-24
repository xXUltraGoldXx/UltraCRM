<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Contact;
use App\Entity\User;
use App\Service\CustomFieldValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enforces the rule "exactly one primary contact per company" server-side.
 *
 * The rule used to live only in the frontend's submit button: creating a
 * person WITH the checkbox set produced a second primary contact, and
 * concurrent PATCHes could tear the state apart. An invariant belongs at
 * the one place nobody can bypass.
 */
final class ContactProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly CustomFieldValidator $customFields,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Contact && $data->getCustomData() !== null) {
            // Validate custom fields against their definition before
            // anything is saved — an open JSON field otherwise accepts
            // anything.
            //
            // On creation the record has NO tenant yet at this point: the
            // TenantAssignListener only sets it on save, i.e. after this
            // processor runs. Without falling back to the logged-in
            // user's tenant, validation found no definitions and silently
            // discarded all values.
            $benutzer = $this->security->getUser();
            $mandant = $data->getTenant()
                ?? ($benutzer instanceof User ? $benutzer->getTenant() : null);

            $ergebnis = $this->customFields->pruefen($data->getCustomData(), 'contact', $mandant);
            if ($ergebnis['fehler'] !== []) {
                throw new \ApiPlatform\Validator\Exception\ValidationException(
                    implode(' ', $ergebnis['fehler'])
                );
            }
            $data->setCustomData($ergebnis['werte']);
        }

        if (!$data instanceof Contact || !$data->isPrimaryContact() || $data->getCompany() === null) {
            return $this->inner->process($data, $operation, $uriVariables, $context);
        }

        // Everything in one transaction: the primary contact either
        // switches completely, or not at all.
        return $this->em->wrapInTransaction(function () use ($data, $operation, $uriVariables, $context) {
            $qb = $this->em->createQueryBuilder()
                ->update(Contact::class, 'c')
                ->set('c.primaryContact', ':aus')
                ->where('c.company = :firma')
                ->andWhere('c.primaryContact = :an')
                ->setParameter('aus', false)
                ->setParameter('an', true)
                ->setParameter('firma', $data->getCompany());

            // Exclude the contact itself when updating an existing one.
            if ($data->getId() !== null) {
                $qb->andWhere('c.id != :selbst')->setParameter('selbst', $data->getId());
            }

            $qb->getQuery()->execute();

            return $this->inner->process($data, $operation, $uriVariables, $context);
        });
    }
}
