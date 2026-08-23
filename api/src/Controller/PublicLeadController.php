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
 * Oeffentliche Lead-Annahme — der einzige Endpunkt ohne Anmeldung.
 *
 * Grundsaetze:
 * - Der Mandant kommt ausschliesslich ueber den Formular-Token, nie aus dem
 *   Request. Sonst koennte jeder Leads in fremde Mandanten schreiben.
 * - Der Doctrine-Mandantenfilter wuerde bei anonymen Anfragen alles
 *   ausblenden (tenant_id = 0). Er wird deshalb fuer die Token-Suche gezielt
 *   und nur dort ausgeschaltet.
 * - Nach aussen wird moeglichst wenig verraten: unbekannter Token und
 *   deaktiviertes Formular sind beide 404, ein Bot-Treffer sieht aus wie
 *   Erfolg.
 */
final class PublicLeadController extends AbstractPublicController
{
    /** Hoechstens so viele Einsendungen je IP innerhalb des Zeitfensters. */
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

        // 1) Rate-Limit vor jeder Datenbankarbeit am Inhalt.
        $ipHash = hash('sha256', ($request->getClientIp() ?? 'unbekannt') . '|' . $this->appSecret);
        if ($this->zuVieleVersuche($ipHash)) {
            return $this->fehler('Zu viele Einsendungen. Bitte später erneut versuchen.', 429);
        }
        $this->em->persist(new LeadAttempt($ipHash));
        $this->em->flush();

        // 2) Honeypot: ein fuer Menschen unsichtbares Feld. Ist es gefuellt,
        //    war es ein Bot — wir antworten wie bei Erfolg, damit er nichts lernt.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['status' => 'ok'], 202);
        }

        // 3) Mandant ueber den Token bestimmen (Filter dafuer kurz aus).
        $form = $this->formularZuToken((string) ($data['token'] ?? ''));
        if ($form === null) {
            return $this->fehler('Formular nicht gefunden.', 404);
        }

        // 4) Einwilligung ist Pflicht — ohne sie darf niemand kontaktiert werden.
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

        // Nachricht als erste Aktivitaet — sie gehoert zum Kontakt, nicht in
        // ein Notizfeld, damit sie im Verlauf auftaucht.
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

        // Double-Opt-in: nur wenn eine Adresse da ist UND der Mandant einen
        // Versandweg hinterlegt hat. Ohne Versand bliebe der Kontakt sonst
        // dauerhaft unbestaetigt und damit unbenutzbar.
        $setting = $this->mailer->findSetting($form->getTenant());
        if ($kontakt->getEmail() && $setting !== null) {
            $token = bin2hex(random_bytes(24));
            $kontakt->setConfirmToken($token);

            $link = sprintf('https://crm.ultragold.de/api/public/leads/confirm/%s', $token);
            $fehler = $this->mailer->send(
                $setting,
                $kontakt->getEmail(),
                'Bitte bestätigen Sie Ihre Anfrage',
                "Guten Tag,\n\nbitte bestätigen Sie mit einem Klick, dass diese Anfrage von Ihnen stammt:\n"
                . $link . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht einfach.\n",
            );

            // Scheitert der Versand, bleibt der Token bestehen. Ihn zu
            // loeschen wuerde den Kontakt sofort kontaktierbar machen,
            // OHNE dass je jemand bestaetigt hat — genau das, wogegen das
            // Double-Opt-in schuetzt (Review-Befund, Schwere 58).
            // Stattdessen wird der Fehlschlag als Aktivitaet sichtbar
            // gemacht, damit ihn jemand bearbeiten kann.
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
     * Bestaetigungslink aus der Opt-in-Mail. Bewusst GET: der Empfaenger
     * klickt in seinem Mailprogramm.
     */
    #[Route('/api/public/leads/confirm/{token}', name: 'public_lead_confirm', methods: ['GET'])]
    public function confirm(string $token, Request $request): Response
    {
        // Auch hier begrenzen: die Token-Suche laeuft ohne Mandantenfilter
        // ueber die gesamte Tabelle. Ohne Limit koennte man mit zufaelligen
        // Tokens beliebig teure Abfragen ausloesen (Review-Befund 55).
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

        $filters = $this->em->getFilters();
        $warAn = $filters->isEnabled('tenant_filter');
        if ($warAn) {
            $filters->disable('tenant_filter');
        }

        try {
            $kontakt = $this->em->getRepository(Contact::class)->findOneBy(['confirmToken' => $token]);
        } finally {
            if ($warAn) {
                $filters->enable('tenant_filter');
            }
        }

        if ($kontakt === null) {
            return new Response($this->seite('Link nicht gültig', 'Dieser Bestätigungslink ist abgelaufen oder wurde bereits benutzt.'), 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        if ($kontakt->getConsentConfirmedAt() === null) {
            $kontakt->setConsentConfirmedAt(new \DateTimeImmutable());
        }
        // Token entwerten — ein Bestaetigungslink gilt genau einmal.
        $kontakt->setConfirmToken(null);
        $this->em->flush();

        return new Response($this->seite('Vielen Dank', 'Ihre Anfrage ist bestätigt. Wir melden uns.'), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Schlichte Bestaetigungsseite — kein Framework, keine externen Dateien. */
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

        $filters = $this->em->getFilters();
        $warAn = $filters->isEnabled('tenant_filter');
        if ($warAn) {
            $filters->disable('tenant_filter');
        }

        try {
            $form = $this->em->getRepository(LeadForm::class)->findOneBy(['token' => $token, 'active' => true]);
        } finally {
            if ($warAn) {
                $filters->enable('tenant_filter');
            }
        }

        return $form;
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
