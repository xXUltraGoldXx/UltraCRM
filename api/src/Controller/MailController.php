<?php

namespace App\Controller;

use App\Entity\MailSetting;
use App\Entity\User;
use App\Service\MailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;

/** Testversand, damit Fehlkonfiguration sofort auffaellt statt beim Kunden. */
#[IsGranted('ROLE_ADMIN')]
final class MailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly MailerFactory $factory,
    ) {
    }

    #[Route('/api/mail/test', name: 'mail_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $daten = json_decode($request->getContent(), true);
        $an = trim((string) ($daten['to'] ?? ''));
        if (!filter_var($an, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Bitte eine gültige Empfängeradresse angeben.'], 422);
        }

        $benutzer = $this->security->getUser();
        $tenant = $benutzer instanceof User ? $benutzer->getTenant() : null;

        $setting = $this->em->getRepository(MailSetting::class)->findOneBy(['tenant' => $tenant]);
        if ($setting === null) {
            return new JsonResponse(['error' => 'Es ist noch kein Versandweg eingerichtet.'], 404);
        }

        $fehler = $this->factory->send(
            $setting,
            $an,
            'Testnachricht aus UltraCRM',
            "Diese Nachricht bestätigt, dass der Versand funktioniert.\n\n"
            . 'Versandweg: ' . $setting->getProvider() . "\n"
            . 'Absender: ' . $this->factory->absender($setting) . "\n",
        );

        if ($fehler !== null) {
            return new JsonResponse([
                'error' => 'Der Versand hat nicht geklappt.',
                'details' => mb_substr($fehler, 0, 300),
            ], 502);
        }

        return new JsonResponse(['status' => 'gesendet', 'an' => $an]);
    }
}
