<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\ChangeLog;
use App\Entity\Contact;
use App\Entity\Deal;
use App\Entity\DeletionLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GDPR tools: subject access, deletion with a record, consent withdrawal.
 *
 * All endpoints run through the logged-in user and therefore through the
 * tenant filter — a contact belonging to a foreign tenant is never even
 * found.
 */
final class PrivacyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Subject access request under Art. 15: everything stored about this
     * person — core data, consent history, activities, deals.
     */
    #[Route('/api/privacy/contacts/{id}/export', name: 'privacy_export', methods: ['GET'])]
    public function export(int $id): Response
    {
        $this->denyAccessUnlessGranted('PERM', 'privacy.view');

        $kontakt = $this->em->getRepository(Contact::class)->find($id);
        if ($kontakt === null) {
            return new JsonResponse(['error' => 'Kontakt nicht gefunden.'], 404);
        }

        $aktivitaeten = $this->em->getRepository(Activity::class)->findBy(['contact' => $kontakt]);
        $vorgaenge = $this->em->getRepository(Deal::class)->findBy(['contact' => $kontakt]);
        // The change history belongs in the disclosure too: "what was
        // done with my data?" is the question behind Art. 15, and it
        // cannot be answered without the history.
        $aenderungen = $this->em->getRepository(ChangeLog::class)->findBy(
            ['subjectType' => 'contact', 'subjectId' => $kontakt->getId()],
            ['changedAt' => 'DESC'],
        );

        $daten = [
            'auskunftErstelltAm' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'hinweis' => 'Diese Auskunft enthält alle zu dieser Person gespeicherten Daten '
                . 'gemäß Art. 15 DSGVO, Stand des Abrufzeitpunkts.',
            'stammdaten' => [
                'vorname' => $kontakt->getFirstName(),
                'nachname' => $kontakt->getLastName(),
                'email' => $kontakt->getEmail(),
                'telefon' => $kontakt->getPhone(),
                'position' => $kontakt->getPosition(),
                'firma' => $kontakt->getCompany()?->getName(),
                'status' => $kontakt->getStatus(),
                'notizen' => $kontakt->getNotes(),
                'erfasstAm' => $kontakt->getCreatedAt()->format(\DATE_ATOM),
            ],
            'herkunft' => [
                'quelle' => $kontakt->getSource(),
                'erlaeuterung' => 'Woher der Datensatz stammt (Art. 14 Abs. 2 lit. f DSGVO).',
            ],
            'einwilligung' => [
                'erteiltAm' => $kontakt->getConsentGivenAt()?->format(\DATE_ATOM),
                'wortlaut' => $kontakt->getConsentText(),
                'widerrufenAm' => $kontakt->getConsentWithdrawnAt()?->format(\DATE_ATOM),
                'darfKontaktiertWerden' => $kontakt->isContactable(),
            ],
            'aufbewahrung' => [
                'loeschungVorgemerktFuer' => $kontakt->getDeleteAfter()?->format('Y-m-d'),
            ],
            'aktivitaeten' => array_map(static fn (Activity $a) => [
                'art' => $a->getType(),
                'betreff' => $a->getSubject(),
                'inhalt' => $a->getBody(),
                'faelligAm' => $a->getDueAt()?->format(\DATE_ATOM),
                'erledigt' => $a->isDone(),
                'erfasstAm' => $a->getCreatedAt()->format(\DATE_ATOM),
            ], $aktivitaeten),
            'aenderungen' => array_map(static fn (ChangeLog $c) => [
                'feld' => $c->getField(),
                'vorher' => $c->getOldValue(),
                'nachher' => $c->getNewValue(),
                'geaendertVon' => $c->getChangedBy(),
                'geaendertAm' => $c->getChangedAt()->format(\DATE_ATOM),
            ], $aenderungen),
            'vorgaenge' => array_map(static fn (Deal $d) => [
                'titel' => $d->getTitle(),
                'wert' => $d->getValue(),
                'waehrung' => $d->getCurrency(),
                'phase' => $d->getStage()?->getName(),
                'abgeschlossenAm' => $d->getClosedAt()?->format(\DATE_ATOM),
            ], $vorgaenge),
        ];

        $antwort = new JsonResponse($daten, 200, [], false);
        $antwort->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        $antwort->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="auskunft-kontakt-%d.json"', $id)
        );

        return $antwort;
    }

    /**
     * Deletion under Art. 17. The contact and everything attached to it
     * disappears; only a record WITHOUT personal data remains.
     *
     * Administrators only. Permanent deletion is more destructive than
     * a regular contact deletion — it must not be protected more weakly
     * than that.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/api/privacy/contacts/{id}/erase', name: 'privacy_erase', methods: ['POST'])]
    public function erase(int $id, Request $request): Response
    {
        // Permanently deleting a person (Art. 17) gets its own permission.
        // No template grants it by default — only the admin account
        // passes until someone deliberately assigns it. Intentionally
        // conservative for now: the permission is configurable, but
        // starts out admin-only.
        $this->denyAccessUnlessGranted('PERM', 'privacy.delete');

        $daten = json_decode($request->getContent(), true);
        $grund = trim((string) ($daten['reason'] ?? ''));
        if ($grund === '') {
            return new JsonResponse(['error' => 'Bitte einen Löschgrund angeben.'], 422);
        }

        $kontakt = $this->em->getRepository(Contact::class)->find($id);
        if ($kontakt === null) {
            return new JsonResponse(['error' => 'Kontakt nicht gefunden.'], 404);
        }

        $mandant = $kontakt->getTenant();
        $aktivitaeten = $this->em->getRepository(Activity::class)->findBy(['contact' => $kontakt]);
        $vorgaenge = $this->em->getRepository(Deal::class)->findBy(['contact' => $kontakt]);

        // The change log contains old and new field values, i.e.
        // personal data (a former name, an old phone number). It MUST be
        // deleted along with the contact — otherwise the name would
        // still sit in the log after the "deletion", and it would not be
        // one.
        $protokolleintraege = $this->em->getRepository(ChangeLog::class)->findBy(
            ['subjectType' => 'contact', 'subjectId' => $kontakt->getId()],
        );

        // Pseudonym: allows a later request to be matched up without
        // revealing the deleted data.
        $pseudonym = substr(hash('sha256', $id . '|' . ($mandant?->getId() ?? 0) . '|' . $this->appSecret), 0, 32);

        $benutzer = $this->security->getUser();
        $protokoll = new DeletionLog(
            'contact',
            $pseudonym,
            mb_substr($grund, 0, 200),
            $benutzer instanceof User ? $benutzer->getUsername() : null,
            count($aktivitaeten) + count($vorgaenge) + count($protokolleintraege),
        );
        $protokoll->setTenant($mandant);

        foreach ($aktivitaeten as $a) {
            $this->em->remove($a);
        }

        foreach ($protokolleintraege as $c) {
            $this->em->remove($c);
        }
        // Deals remain as a business record but lose their link to the
        // person — accounting retention duties and the right to erasure
        // do not conflict here.
        foreach ($vorgaenge as $d) {
            $d->setContact(null);
        }

        $this->em->remove($kontakt);
        $this->em->persist($protokoll);
        $this->em->flush();

        return new JsonResponse([
            'status' => 'geloescht',
            'protokoll' => [
                'pseudonym' => $pseudonym,
                'mitgeloeschteDatensaetze' => $protokoll->getRelatedCount(),
            ],
        ]);
    }

    /** Withdraws consent — no more marketing contact from now on. */
    #[IsGranted('PERM', 'privacy.manage')]
    #[Route('/api/privacy/contacts/{id}/withdraw', name: 'privacy_withdraw', methods: ['POST'])]
    public function withdraw(int $id): Response
    {
        $this->denyAccessUnlessGranted('PERM', 'privacy.manage');

        $kontakt = $this->em->getRepository(Contact::class)->find($id);
        if ($kontakt === null) {
            return new JsonResponse(['error' => 'Kontakt nicht gefunden.'], 404);
        }

        if ($kontakt->getConsentWithdrawnAt() === null) {
            $kontakt->setConsentWithdrawnAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return new JsonResponse([
            'status' => 'widerrufen',
            'widerrufenAm' => $kontakt->getConsentWithdrawnAt()?->format(\DATE_ATOM),
            'darfKontaktiertWerden' => $kontakt->isContactable(),
        ]);
    }

    /** Contacts whose retention period has expired. */
    #[Route('/api/privacy/due-deletions', name: 'privacy_due', methods: ['GET'])]
    public function dueDeletions(): Response
    {
        $this->denyAccessUnlessGranted('PERM', 'privacy.view');

        $faellig = $this->em->createQuery(
            'SELECT c FROM App\Entity\Contact c WHERE c.deleteAfter IS NOT NULL AND c.deleteAfter <= :heute ORDER BY c.deleteAfter ASC'
        )->setParameter('heute', new \DateTimeImmutable('today'))->getResult();

        return new JsonResponse([
            'anzahl' => count($faellig),
            'kontakte' => array_map(static fn (Contact $c) => [
                'id' => $c->getId(),
                'name' => $c->getDisplayName(),
                'loeschenAb' => $c->getDeleteAfter()?->format('Y-m-d'),
            ], $faellig),
        ]);
    }
}
