<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contact;
use App\Entity\LeadAttempt;
use App\Entity\LeadForm;
use App\Service\MailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public lead intake — the only endpoint without authentication.
 *
 * Principles:
 * - The tenant comes exclusively from the form token, never from the
 *   request. Otherwise anyone could write leads into a foreign tenant.
 * - The Doctrine tenant filter would hide everything on anonymous
 *   requests (tenant_id = 0). It is therefore switched off deliberately,
 *   and only for the token lookup.
 * - As little as possible is revealed to the outside: an unknown token
 *   and a deactivated form both return 404, and a bot hit looks like
 *   success.
 */
final class PublicLeadController extends AbstractPublicController
{
    /** Maximum submissions per IP within the time window. */
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly string $appSecret,
        private readonly MailerFactory $mailer,
    ) {
    }

    #[Route('/api/public/leads', name: 'public_lead_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->fehler('Die Anfrage konnte nicht gelesen werden.', 400);
        }

        // 1) Rate limit before any database work on the content.
        $ipHash = hash('sha256', ($request->getClientIp() ?? 'unbekannt') . '|' . $this->appSecret);
        if ($this->zuVieleVersuche($ipHash)) {
            return $this->fehler('Zu viele Einsendungen. Bitte später erneut versuchen.', 429);
        }
        $this->em->persist(new LeadAttempt($ipHash));
        $this->em->flush();

        // 2) Honeypot: a field invisible to humans. If it is filled in,
        //    it was a bot — we respond as if it succeeded so it learns
        //    nothing.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['status' => 'ok'], 202);
        }

        // 3) Determine the tenant via the token (filter briefly off for this).
        $form = $this->formularZuToken((string) ($data['token'] ?? ''));
        if ($form === null) {
            return $this->fehler('Formular nicht gefunden.', 404);
        }

        // 4) Consent is mandatory — nobody may be contacted without it.
        if (($data['consent'] ?? false) !== true) {
            return $this->fehler('Ohne Einwilligung können wir die Anfrage nicht speichern.', 422);
        }

        $kontakt = (new Contact())
            ->setTenant($form->getTenant())
            ->setFirstName($this->kurz($data['firstName'] ?? null, 120))
            ->setLastName($this->kurz($data['lastName'] ?? null, 120) ?? 'Unbekannt')
            ->setEmail($this->kurz($data['email'] ?? null, 180))
            ->setPhone($this->kurz($data['phone'] ?? null, 40))
            ->setSource('formular')
            ->setStatus('neu')
            ->setConsentGivenAt(new \DateTimeImmutable())
            ->setConsentText($form->getConsentText());

        $verstoesse = $this->validator->validate($kontakt);
        if (count($verstoesse) > 0) {
            return $this->fehler((string) $verstoesse[0]->getMessage(), 422);
        }

        $this->em->persist($kontakt);

        // Message as the first activity — it belongs to the contact, not
        // in a notes field, so it shows up in the history.
        $nachricht = $this->kurz($data['message'] ?? null, 4000);
        if ($nachricht !== null) {
            $this->em->persist(
                (new Activity())
                    ->setTenant($form->getTenant())
                    ->setType('notiz')
                    ->setSubject('Anfrage über ' . $form->getName())
                    ->setBody($nachricht)
                    ->setContact($kontakt)
            );
        }

        // Double opt-in: whoever gave an address gets a confirmation
        // token — even when the tenant has no mail transport configured
        // (yet).
        //
        // This token used to be skipped in that case, so the contact
        // would not stay "unusable". But that meant: as long as no SMTP
        // is configured, every form lead instantly counts as fine to
        // market to, without anyone ever having confirmed it. That is
        // the convenient direction, not the safe one — and it hits
        // exactly the state of a freshly set up tenant.
        $setting = $this->mailer->findSetting($form->getTenant());
        if ($kontakt->getEmail()) {
            $token = bin2hex(random_bytes(24));
            $kontakt->setConfirmToken($token);

            if ($setting === null) {
                // No mail transport: nobody can request the confirmation.
                // This must not happen silently, or the tenant will
                // wonder why their leads never get approved.
                $this->em->persist(
                    (new Activity())
                        ->setTenant($form->getTenant())
                        ->setType('aufgabe')
                        ->setSubject('Kein Versandweg hinterlegt — Bestätigung nicht verschickt')
                        ->setBody(
                            'Für ' . $kontakt->getEmail() . ' konnte keine Bestätigungsmail '
                            . 'verschickt werden, weil unter Einstellungen kein Versandweg '
                            . 'eingerichtet ist. Der Kontakt bleibt so lange nicht für Werbung '
                            . 'freigegeben.'
                        )
                        ->setDueAt(new \DateTimeImmutable('+1 day'))
                        ->setContact($kontakt)
                );

                $this->em->flush();

                return new JsonResponse(['status' => 'ok'], 202);
            }

            $link = sprintf('https://crm.ultragold.de/api/public/leads/confirm/%s', $token);
            $fehler = $this->mailer->send(
                $setting,
                $kontakt->getEmail(),
                'Bitte bestätigen Sie Ihre Anfrage',
                "Guten Tag,\n\nbitte bestätigen Sie mit einem Klick, dass diese Anfrage von Ihnen stammt:\n"
                . $link . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht einfach.\n",
            );

            // If sending fails, the token stays in place. Deleting it
            // would make the contact immediately contactable WITHOUT
            // anyone ever having confirmed it — exactly what double
            // opt-in protects against. Instead, the failure is surfaced
            // as an activity so someone can act on it.
            if ($fehler !== null) {
                $this->em->persist(
                    (new Activity())
                        ->setTenant($form->getTenant())
                        ->setType('aufgabe')
                        ->setSubject('Bestätigungsmail konnte nicht zugestellt werden')
                        ->setBody(
                            'An ' . $kontakt->getEmail() . ' ließ sich keine Bestätigung senden. '
                            . 'Der Kontakt bleibt so lange nicht für Werbung freigegeben. '
                            . "Grund: " . mb_substr($fehler, 0, 300)
                        )
                        ->setDueAt(new \DateTimeImmutable('+1 day'))
                        ->setContact($kontakt)
                );
            }
        }

        $this->em->flush();

        return new JsonResponse(['status' => 'ok'], 202);
    }

    /**
     * Confirmation link from the opt-in mail. Deliberately GET: the
     * recipient clicks it in their mail client.
     */
    #[Route('/api/public/leads/confirm/{token}', name: 'public_lead_confirm', methods: ['GET'])]
    public function confirm(string $token, Request $request): Response
    {
        // Rate-limit here too: the token lookup runs without the tenant
        // filter across the entire table. Without a limit, random tokens
        // could be used to trigger arbitrarily expensive queries.
        $ipHash = hash('sha256', ($request->getClientIp() ?? 'unbekannt') . '|' . $this->appSecret);
        if ($this->zuVieleVersuche($ipHash, 20)) {
            return new Response(
                $this->seite('Zu viele Versuche', 'Bitte versuchen Sie es später noch einmal.'),
                429,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }
        $this->em->persist(new LeadAttempt($ipHash));
        $this->em->flush();

        $kontakt = $this->ohneMandantenfilter(
            $this->em,
            fn () => $this->em->getRepository(Contact::class)->findOneBy(['confirmToken' => $token])
        );

        if ($kontakt === null) {
            return new Response($this->seite('Link nicht gültig', 'Dieser Bestätigungslink ist abgelaufen oder wurde bereits benutzt.'), 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        if ($kontakt->getConsentConfirmedAt() === null) {
            $kontakt->setConsentConfirmedAt(new \DateTimeImmutable());
        }
        // Invalidate the token — a confirmation link is valid exactly once.
        $kontakt->setConfirmToken(null);
        $this->em->flush();

        return new Response($this->seite('Vielen Dank', 'Ihre Anfrage ist bestätigt. Wir melden uns.'), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Plain confirmation page — no framework, no external files. */
    private function seite(string $titel, string $text): string
    {
        return sprintf(
            '<!doctype html><html lang="de"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>%1$s</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
            . 'background:#f2f2f7;color:#1c1c1e}main{max-width:32rem;padding:2.5rem;text-align:center;'
            . 'background:#fff;border-radius:22px;box-shadow:0 8px 24px rgba(0,0,0,.06)}'
            . 'h1{font-size:1.75rem;margin:0 0 .5rem}p{margin:0;color:#3c3c4399}'
            . '@media(prefers-color-scheme:dark){body{background:#000;color:#f5f5f7}'
            . 'main{background:#1c1c1e;box-shadow:none}p{color:#ebebf599}}</style>'
            . '<main><h1>%1$s</h1><p>%2$s</p></main>',
            htmlspecialchars($titel, \ENT_QUOTES),
            htmlspecialchars($text, \ENT_QUOTES),
        );
    }

    private function zuVieleVersuche(string $ipHash, ?int $grenze = null): bool
    {
        $grenze ??= self::MAX_ATTEMPTS;
        $seit = new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes');

        $anzahl = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\LeadAttempt a WHERE a.ipHash = :h AND a.createdAt >= :seit'
        )->setParameter('h', $ipHash)->setParameter('seit', $seit)->getSingleScalarResult();

        return $anzahl >= $grenze;
    }

    private function formularZuToken(string $token): ?LeadForm
    {
        if (strlen($token) < 16) {
            return null;
        }

        return $this->ohneMandantenfilter(
            $this->em,
            fn () => $this->em->getRepository(LeadForm::class)->findOneBy(['token' => $token, 'active' => true])
        );
    }

    private function kurz(mixed $wert, int $max): ?string
    {
        $wert = is_string($wert) ? trim($wert) : '';

        return $wert === '' ? null : mb_substr($wert, 0, $max);
    }

    private function fehler(string $text, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $text], $status);
    }
}
