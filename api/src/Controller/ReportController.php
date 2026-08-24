<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\Deal;
use App\Entity\Stage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Auswertung. Die Abfragen laufen ueber den Mandantenfilter, es kann also
 * niemand versehentlich ueber Mandantengrenzen hinweg summieren.
 */
final class ReportController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/reports/summary', name: 'report_summary', methods: ['GET'])]
    public function summary(): Response
    {
        // Klassen-Attribute wurden hier nicht ausgewertet — deshalb
        // ausdrücklich prüfen, damit die Rechte sicher greifen.
        $this->denyAccessUnlessGranted('PERM', 'reports.view');

        /** @var Deal[] $deals */
        $deals = $this->em->getRepository(Deal::class)->findAll();
        /** @var Contact[] $kontakte */
        $kontakte = $this->em->getRepository(Contact::class)->findAll();

        // Funnel: Anzahl und Wert je Phase, in der Reihenfolge der Pipeline.
        // Die Phasen kommen aus der Datenbank, nicht mehr aus einer
        // Konstante — jeder Mandant hat seine eigenen.
        // Nach Pipeline gruppiert, innerhalb der Pipeline nach Position:
        // sonst mischen sich bei mehreren Pipelines die Phasen mit gleicher
        // Position ineinander und der Funnel liest sich als Durcheinander.
        /** @var Stage[] $phasen */
        $phasen = $this->em->createQuery(
            'SELECT s FROM App\Entity\Stage s JOIN s.pipeline p ORDER BY p.position ASC, p.id ASC, s.position ASC'
        )->getResult();

        $funnel = [];
        foreach ($phasen as $phase) {
            $inPhase = array_filter(
                $deals,
                static fn (Deal $d) => $d->getStage()?->getId() === $phase->getId()
            );
            $funnel[] = [
                'phase' => $phase->getName(),
                'pipeline' => $phase->getPipeline()?->getName(),
                'art' => $phase->getArt(),
                'anzahl' => count($inPhase),
                'wert' => array_sum(array_map(static fn (Deal $d) => (float) $d->getValue(), $inPhase)),
            ];
        }

        $gewonnen = array_filter(
            $deals,
            static fn (Deal $d) => $d->getStage()?->getArt() === Stage::GEWONNEN
        );
        $verloren = array_filter(
            $deals,
            static fn (Deal $d) => $d->getStage()?->getArt() === Stage::VERLOREN
        );
        $abgeschlossen = count($gewonnen) + count($verloren);

        // Herkunft der Kontakte — zeigt, welcher Kanal wirklich liefert.
        $quellen = [];
        foreach ($kontakte as $k) {
            $quellen[$k->getSource()] = ($quellen[$k->getSource()] ?? 0) + 1;
        }
        arsort($quellen);

        $offen = array_filter($deals, static fn (Deal $d) => $d->isOpen());

        return new JsonResponse([
            'funnel' => $funnel,
            'quellen' => array_map(
                static fn ($q, $n) => ['quelle' => $q, 'anzahl' => $n],
                array_keys($quellen),
                array_values($quellen),
            ),
            'kennzahlen' => [
                'kontakte' => count($kontakte),
                'kontaktierbar' => count(array_filter($kontakte, static fn (Contact $c) => $c->isContactable())),
                'offeneVorgaenge' => count($offen),
                'offenerWert' => array_sum(array_map(static fn (Deal $d) => (float) $d->getValue(), $offen)),
                'gewonnenerWert' => array_sum(array_map(static fn (Deal $d) => (float) $d->getValue(), $gewonnen)),
                // Quote nur ausweisen, wenn ueberhaupt etwas abgeschlossen wurde —
                // "0 %" bei null Abschluessen waere eine falsche Aussage.
                'abschlussquote' => $abgeschlossen > 0
                    ? round(count($gewonnen) / $abgeschlossen * 100, 1)
                    : null,
            ],
        ]);
    }
}
