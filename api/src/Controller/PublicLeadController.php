<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contact;
use App\Entity\LeadAttempt;
use App\Entity\LeadForm;
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

        $this->em->flush();

        return new JsonResponse(['status' => 'ok'], 202);
    }

    private function zuVieleVersuche(string $ipHash): bool
    {
        $seit = new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes');

        $anzahl = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\LeadAttempt a WHERE a.ipHash = :h AND a.createdAt >= :seit'
        )->setParameter('h', $ipHash)->setParameter('seit', $seit)->getSingleScalarResult();

        return $anzahl >= self::MAX_ATTEMPTS;
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
