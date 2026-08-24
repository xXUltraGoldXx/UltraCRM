<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\ChangeLog;
use App\Entity\Contact;
use App\Entity\Deal;
use App\Entity\User;
use App\Service\ContactMerger;
use App\Service\DuplicateFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dubletten anzeigen und zusammenfuehren.
 *
 * Zusammenfuehren ist unumkehrbar, deshalb entscheidet immer ein Mensch,
 * welcher Datensatz bleibt. Automatisch wird nichts verschmolzen — bei
 * "gleicher Name in derselben Firma" waeren Fehlentscheidungen sonst
 * programmiert.
 */
final class DuplicateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly DuplicateFinder $finder,
        private readonly ContactMerger $merger,
    ) {
    }

    #[Route('/api/duplicates', name: 'duplicates_list', methods: ['GET'])]
    public function liste(): Response
    {
        $this->denyAccessUnlessGranted('PERM', 'contacts.view');

        $mandant = $this->mandant();
        if ($mandant === null) {
            // Ein Superadmin haengt an keinem Mandanten. Ohne diese Sperre
            // wuerde findBy() ohne Kriterien die Kontakte ALLER Mandanten
            // laden und sie miteinander als Dublette vorschlagen.
            return new JsonResponse(
                ['error' => 'Dubletten koennen nur innerhalb eines Mandanten gesucht werden.'],
                403
            );
        }

        $gruppen = [];
        foreach ($this->finder->finden($mandant) as $g) {
            $gruppen[] = [
                'sicherheit' => $g['sicherheit'],
                'grund' => $g['grund'],
                'kontakte' => array_map(static fn (Contact $k) => [
                    'id' => $k->getId(),
                    'name' => $k->getDisplayName(),
                    'email' => $k->getEmail(),
                    'telefon' => $k->getPhone(),
                    'firma' => $k->getCompany()?->getName(),
                    'herkunft' => $k->getSource(),
                    'erfasstAm' => $k->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'darfKontaktiertWerden' => $k->isContactable(),
                ], $g['kontakte']),
            ];
        }

        return new JsonResponse(['anzahl' => count($gruppen), 'gruppen' => $gruppen]);
    }

    /**
     * Fuehrt zwei Kontakte zusammen: der Zieldatensatz bleibt, der andere
     * verschwindet. Leere Felder des Ziels werden aus der Quelle gefuellt —
     * vorhandene NIE ueberschrieben, sonst gehen Korrekturen verloren.
     */
    #[Route('/api/duplicates/merge', name: 'duplicates_merge', methods: ['POST'])]
    public function zusammenfuehren(Request $request): Response
    {
        // Zusammenfuehren loescht einen Kontakt unwiderruflich. Das reguläre
        // DELETE auf Contact verlangt ROLE_ADMIN — dieselbe Wirkung muss hier
        // dieselbe Huerde haben, sonst laeuft der Loeschschutz ins Leere
        // (Analyse.md C7: Rechte an der Wirkung ausrichten, nicht am Ort).
        $this->denyAccessUnlessGranted('PERM', 'contacts.manage');
        $this->denyAccessUnlessGranted('PERM', 'contacts.delete');

        $mandant = $this->mandant();
        if ($mandant === null) {
            return new JsonResponse(
                ['error' => 'Zusammenfuehren ist nur innerhalb eines Mandanten moeglich.'],
                403
            );
        }

        $daten = json_decode($request->getContent(), true);
        if (!is_array($daten)) {
            return new JsonResponse(['error' => 'Ungueltige Anfrage.'], 422);
        }

        $zielId = filter_var($daten['keep'] ?? null, FILTER_VALIDATE_INT);
        $quelleId = filter_var($daten['merge'] ?? null, FILTER_VALIDATE_INT);

        if ($zielId === false || $quelleId === false
            || $zielId < 1 || $quelleId < 1 || $zielId === $quelleId) {
            return new JsonResponse(['error' => 'Bitte zwei verschiedene Kontakte angeben.'], 422);
        }

        $ziel = $this->em->getRepository(Contact::class)->find($zielId);
        $quelle = $this->em->getRepository(Contact::class)->find($quelleId);

        if ($ziel === null || $quelle === null) {
            return new JsonResponse(['error' => 'Kontakt nicht gefunden.'], 404);
        }

        // Mandantenzugehoerigkeit ausdruecklich pruefen, nicht nur auf den
        // Doctrine-Filter vertrauen (Analyse.md C13).
        if ($ziel->getTenant() !== $mandant || $quelle->getTenant() !== $mandant) {
            return new JsonResponse(['error' => 'Kontakt nicht gefunden.'], 404);
        }

        // Nur zusammenfuehren, was auch als Dublette erkannt wurde. Sonst
        // waere /api/duplicates/merge ein zweiter Loeschweg fuer beliebige
        // Kontakte.
        $sicher = null;
        foreach ($this->finder->finden($mandant) as $g) {
            $ids = array_map(static fn (Contact $k) => $k->getId(), $g['kontakte']);
            if (in_array($zielId, $ids, true) && in_array($quelleId, $ids, true)) {
                $sicher = $g['sicherheit'] === 'sicher';
                break;
            }
        }
        if ($sicher === null) {
            return new JsonResponse(
                ['error' => 'Diese beiden Kontakte werden nicht als Dublette gefuehrt.'],
                422
            );
        }

        try {
            $uebernommen = $this->merger->uebernehmen($ziel, $quelle, $sicher);
        } catch (\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        // Verlauf und Vorgaenge umhaengen statt loeschen — sie gehoeren zur
        // Geschichte des Kunden.
        $aktivitaeten = $this->em->getRepository(Activity::class)->findBy(['contact' => $quelle]);
        foreach ($aktivitaeten as $a) {
            $a->setContact($ziel);
        }

        $vorgaenge = $this->em->getRepository(Deal::class)->findBy(['contact' => $quelle]);
        foreach ($vorgaenge as $d) {
            $d->setContact($ziel);
        }

        // Protokolleintraege des aufgeloesten Datensatzes entfernen: sie
        // verweisen auf eine Id, die es gleich nicht mehr gibt, und enthalten
        // Personendaten (siehe Analyse.md C15).
        foreach ($this->em->getRepository(ChangeLog::class)->findBy(
            ['subjectType' => 'contact', 'subjectId' => $quelle->getId()]
        ) as $c) {
            $this->em->remove($c);
        }

        $benutzer = $this->security->getUser();
        $this->em->persist(new ChangeLog(
            'contact',
            $ziel->getId(),
            'zusammengefuehrt',
            $quelle->getDisplayName(),
            $ziel->getDisplayName(),
            $benutzer instanceof User ? $benutzer->getUsername() : null,
        ));

        $this->em->remove($quelle);
        $this->em->flush();

        return new JsonResponse([
            'status' => 'zusammengefuehrt',
            'bleibt' => ['id' => $ziel->getId(), 'name' => $ziel->getDisplayName()],
            'uebernommeneFelder' => $uebernommen,
            'umgehaengt' => ['aktivitaeten' => count($aktivitaeten), 'vorgaenge' => count($vorgaenge)],
        ]);
    }

    private function mandant(): ?\App\Entity\Tenant
    {
        $benutzer = $this->security->getUser();

        return $benutzer instanceof User ? $benutzer->getTenant() : null;
    }
}
