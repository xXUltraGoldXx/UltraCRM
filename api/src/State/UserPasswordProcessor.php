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

    /**
     * Ein Benutzer darf sich selbst bearbeiten — Anzeigename, Passwort. Rollen,
     * Rechte, Aktiv-Schalter und Mandant gehoeren nicht dazu.
     *
     * Ohne diese Pruefung genuegte ein PATCH auf den eigenen Datensatz mit
     * `{"roles": ["ROLE_ADMIN"]}`, um sich zum Administrator zu machen: die
     * Operation erlaubt `object == user`, und beide Felder stehen in der
     * Schreibgruppe (Analyse.md C37).
     */
    private function keineSelbstBefoerderung(User $data): void
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $vorher = $this->em->getUnitOfWork()->getOriginalEntityData($data);
        if ($vorher === []) {
            // Kein bekannter Vorzustand: das waere ein Anlegevorgang, und den
            // laesst die Operation ohnehin nur Administratoren durch.
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
