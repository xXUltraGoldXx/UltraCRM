<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hasht das plainPassword und setzt den Mandanten, bevor ein User
 * gespeichert wird.
 *
 * Der Mandant kommt vom anlegenden Administrator, NIE aus dem Request:
 * sonst koennte ein Mandanten-Admin Benutzer in fremden Mandanten anlegen.
 * Ohne diese Zuweisung waere ein neuer Benutzer ausserdem nutzlos — der
 * Mandantenfilter steht auf "zu" und er saehe gar nichts.
 *
 * @implements ProcessorInterface<User, User|void>
 */
final class UserPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserPasswordHasherInterface $passwordHasher,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof User) {
            if ($data->getPlainPassword()) {
                $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPlainPassword()));
                $data->eraseCredentials();
            }

            // Nur beim Anlegen und nur, wenn der Anlegende selbst zu einem
            // Mandanten gehoert. Ein Superadmin ohne Mandanten legt bewusst
            // mandantenlose Benutzer an (die dann zugeordnet werden muessen).
            if ($data->getId() === null && $data->getTenant() === null) {
                $anlegender = $this->security->getUser();
                if ($anlegender instanceof User && $anlegender->getTenant() !== null) {
                    $data->setTenant($anlegender->getTenant());
                }
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
