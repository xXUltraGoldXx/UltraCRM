<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'displayName' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'permissions' => $user->getPermissions(),
            // Vacation management: the vacation balance display needs the
            // user's own yearly allowance without an extra request to the
            // (ROLE_ADMIN-only) /users endpoint.
            'vacationDaysPerYear' => $user->getVacationDaysPerYear(),
        ]);
    }

    /**
     * Self-service password change. Deliberately its own, simple endpoint
     * rather than going through the API Platform PATCH path
     * (UserPasswordProcessor): here the current password MUST be
     * verified first, which is not a generic field update.
     *
     * Accepted JWT limitation: LexikJWT is stateless — a token already
     * issued stays valid until it expires (token_ttl, not overridden
     * here, bundle default 3600s/1h), even after a password change. No
     * token-version invalidation mechanism is built for this; for this
     * internal portal with a one-hour TTL that is accepted as sufficient,
     * with no need for session invalidation.
     */
    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $body = json_decode($request->getContent(), true) ?? [];
        $currentPassword = (string) ($body['currentPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            throw new BadRequestHttpException('Aktuelles und neues Passwort sind erforderlich.');
        }

        // Fresh hash comparison against the database (not against an old
        // password possibly carried in the token) — no detail leak, the
        // same generic message for "wrong password" as for any other
        // rejection reason.
        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new AccessDeniedHttpException('Aktuelles Passwort ist falsch.');
        }

        if (mb_strlen($newPassword) < 8) {
            throw new UnprocessableEntityHttpException('Neues Passwort muss mindestens 8 Zeichen haben.');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $em->flush();

        return $this->json(['changed' => true]);
    }
}
