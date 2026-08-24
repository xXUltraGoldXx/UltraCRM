<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hashes the plainPassword and sets the tenant before a user is saved.
 *
 * The tenant comes from the creating administrator, NEVER from the
 * request: otherwise a tenant admin could create users in a foreign
 * tenant. Without this assignment a new user would also be useless —
 * the tenant filter fails closed and they would see nothing at all.
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
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof User) {
            $this->keineSelbstBefoerderung($data);

            if ($data->getPlainPassword()) {
                $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPlainPassword()));
                $data->eraseCredentials();
            }

            // Only on creation, and only if the creator themselves
            // belongs to a tenant. A superadmin without a tenant
            // deliberately creates tenant-less users (which then need to
            // be assigned).
            if ($data->getId() === null && $data->getTenant() === null) {
                $anlegender = $this->security->getUser();
                if ($anlegender instanceof User && $anlegender->getTenant() !== null) {
                    $data->setTenant($anlegender->getTenant());
                }
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * A user may edit themselves — display name, password. Roles,
     * permissions, the active flag and the tenant are not part of that.
     *
     * Without this check, a PATCH on one's own record with
     * `{"roles": ["ROLE_ADMIN"]}` was enough to self-promote to
     * administrator: the operation allows `object == user`, and both
     * fields are in the writable group.
     */
    private function keineSelbstBefoerderung(User $data): void
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $vorher = $this->em->getUnitOfWork()->getOriginalEntityData($data);
        if ($vorher === []) {
            // No known prior state: that would be a creation, and the
            // operation only lets administrators through for that anyway.
            return;
        }

        $unveraenderbar = [
            'roles' => $data->getRoles(),
            'permissions' => $data->getPermissions(),
            'active' => $data->isActive(),
            'tenant' => $data->getTenant(),
        ];

        foreach ($unveraenderbar as $feld => $jetzt) {
            if (!array_key_exists($feld, $vorher)) {
                continue;
            }

            if ($vorher[$feld] !== $jetzt) {
                throw new AccessDeniedHttpException(
                    'Rollen, Rechte, Mandant und der Aktiv-Schalter lassen sich nur von einem '
                    . 'Administrator aendern.'
                );
            }
        }
    }
}
