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
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Test send, so misconfiguration shows up immediately instead of with a customer. */
#[IsGranted('ROLE_ADMIN')]
final class MailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly MailerFactory $factory,
        private readonly LoggerInterface $logger,
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
            // Raw mailer messages can expose server paths, DNS and TLS
            // details — those belong in the log, not with the client.
            // Only the deliberately worded messages coming from our own
            // code are passed through.
            $this->logger->error('Testmail fehlgeschlagen', ['grund' => $fehler]);

            $verstaendlich = str_starts_with($fehler, 'Es ist kein Server')
                || str_starts_with($fehler, 'Für diesen Benutzernamen')
                || str_starts_with($fehler, 'Das gespeicherte Passwort')
                || str_starts_with($fehler, 'Diese Serveradresse')
                || str_starts_with($fehler, 'Der Servername')
                || str_starts_with($fehler, 'Dieser Server verweist');

            return new JsonResponse([
                'error' => $verstaendlich
                    ? $fehler
                    : 'Der Versand hat nicht geklappt. Bitte Servername, Port und Zugangsdaten prüfen.',
            ], 502);
        }

        return new JsonResponse(['status' => 'gesendet', 'an' => $an]);
    }
}
