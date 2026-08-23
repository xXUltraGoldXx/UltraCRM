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
            // Modul #9 Urlaubsverwaltung: Urlaubskonto-Anzeige in UrlaubView.vue
            // braucht das eigene Jahreskontingent, ohne einen Extra-Request ans
            // (ROLE_ADMIN-only) /users-Endpunkt zu benoetigen.
            'vacationDaysPerYear' => $user->getVacationDaysPerYear(),
        ]);
    }

    /**
     * Passwort-Selbstbedienung (Paket 1, Punkt 3). Bewusst ein eigener, simpler
     * Endpunkt statt ueber den API-Platform-Patch-Weg (UserPasswordProcessor):
     * hier MUSS das aktuelle Passwort gegengeprueft werden, das ist kein
     * generisches Feld-Update.
     *
     * JWT-Einschraenkung (bewusst akzeptiert, siehe Auftrag): LexikJWT ist
     * stateless -- ein bereits ausgestelltes Token bleibt bis zum Ablauf
     * (token_ttl, hier nicht ueberschrieben, Bundle-Default 3600s/1h) gueltig,
     * auch nach einem Passwortwechsel. KEINE token_version-Mechanik wie beim
     * Trading-Bot nachgebaut -- fuer dieses interne Portal mit Ein-Stunden-TTL
     * bewusst als ausreichend akzeptiert, kein Session-Invalidierungs-Bedarf.
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

        // Frischer Hash-Vergleich gegen die DB (nicht gegen ein evtl. im Token
        // mitgefuehrtes altes Passwort) -- kein Detail-Leak, dieselbe generische
        // Meldung fuer "falsches Passwort" wie fuer jeden anderen Ablehnungsgrund.
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
