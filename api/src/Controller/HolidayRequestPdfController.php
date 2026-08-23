<?php

namespace App\Controller;

use App\Entity\HolidayRequest;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Urlaubsantrag als PDF (Paket 1, Punkt 1). Muster SubmissionPdfController
 * (dompdf, A4). Routing-Falle geprueft: /holiday_requests/{id}/pdf ist ein
 * ZUSAETZLICHES Segment NACH {id}, kollidiert nicht mit /holiday_requests/
 * calendar (das waere nur ein Problem, wenn "pdf" direkt anstelle von {id}
 * stuende) -- dieselbe ungefaehrliche Form wie das bereits bestehende
 * /form_submissions/{id}/pdf neben /form_submissions/{id}.
 *
 * Security: diese Route ist ein PLAIN Symfony-Controller, kein API-Platform-
 * Operation -- die Get-Security-Expression der Entity greift hier NICHT
 * automatisch. Deshalb dieselbe Regel manuell nachgebaut (Eigentuemer ODER
 * holiday.manage ODER ROLE_ADMIN), 1:1 wie HolidayRequest::Get.
 */
class HolidayRequestPdfController extends AbstractController
{
    #[Route('/api/holiday_requests/{id}/pdf', name: 'holiday_request_pdf', methods: ['GET'])]
    public function pdf(HolidayRequest $holidayRequest): Response
    {
        $this->assertReadable($holidayRequest);

        $html = $this->renderHtml($holidayRequest);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('urlaubsantrag-%d.pdf', $holidayRequest->getId());

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    private function assertReadable(HolidayRequest $holidayRequest): void
    {
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $canManage = $isAdmin || ($user instanceof User && in_array('holiday.manage', $user->getPermissions(), true));
        $isOwner = $user instanceof User
            && $holidayRequest->getRequestedBy()
            && $holidayRequest->getRequestedBy()->getId() === $user->getId();

        if (!$canManage && !$isOwner) {
            // Kein Detail-Leak (z.B. "existiert, gehoert aber X") -- generische 403.
            throw $this->createAccessDeniedException('Kein Zugriff auf diesen Urlaubsantrag.');
        }
    }

    private function renderHtml(HolidayRequest $hr): string
    {
        $statusLabels = [
            'pending' => 'Offen',
            'approved' => 'Genehmigt',
            'rejected' => 'Abgelehnt',
            'withdrawn' => 'Zurückgezogen',
        ];

        $requester = $hr->getRequestedBy();
        $decider = $hr->getDecidedBy();

        $rows = '';
        $rows .= $this->row('Antragsteller', $requester ? $this->esc($requester->getDisplayName()) : '<span class="empty">—</span>');
        $rows .= $this->row('Zeitraum', $this->esc($hr->getStartsAt()->format('d.m.Y') . ' – ' . $hr->getEndsAt()->format('d.m.Y')));
        $rows .= $this->row('Werktage', $this->esc(rtrim(rtrim(number_format($hr->getRequestedDays(), 1, ',', '.'), '0'), ',')));
        $rows .= $this->row('Vertretung', $hr->getRepresentative() ? $this->esc($hr->getRepresentative()) : '<span class="empty">—</span>');
        $rows .= $this->row('Notiz', $hr->getReason() ? nl2br($this->esc($hr->getReason())) : '<span class="empty">—</span>');
        $rows .= $this->row('Status', $this->esc($statusLabels[$hr->getStatus()] ?? $hr->getStatus()));

        if ($hr->getDecidedBy() || $hr->getDecidedAt()) {
            $decidedText = trim(
                ($decider ? $this->esc($decider->getDisplayName()) : '')
                . ($hr->getDecidedAt() ? ' am ' . $this->esc($hr->getDecidedAt()->format('d.m.Y H:i')) : '')
            );
            $rows .= $this->row('Entschieden von', $decidedText ?: '<span class="empty">—</span>');
        }

        if ($hr->getStatus() === 'rejected' && $hr->getRejectedReason()) {
            $rows .= $this->row('Ablehnungsgrund', nl2br($this->esc($hr->getRejectedReason())));
        }

        $title = 'Urlaubsantrag';
        $meta = sprintf('Erstellt am %s', $hr->getCreatedAt()->format('d.m.Y H:i'));

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #1a1c22; font-size: 12px; margin: 0; }
    .header { border-bottom: 3px solid #3b82f6; padding-bottom: 12px; margin-bottom: 18px; }
    .brand { font-size: 11px; color: #3b82f6; font-weight: bold; letter-spacing: 1px; }
    h1 { font-size: 20px; margin: 4px 0 6px; }
    .meta { color: #666; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 8px 10px; vertical-align: top; border-bottom: 1px solid #eee; }
    td.lbl { width: 32%; color: #555; font-weight: bold; }
    .foot { margin-top: 30px; color: #999; font-size: 9px; text-align: center; }
    .empty { color: #bbb; }
</style></head><body>
    <div class="header">
        <div class="brand">ULTRAGOLD PORTAL</div>
        <h1>{$title}</h1>
        <div class="meta">{$meta}</div>
    </div>
    <table>{$rows}</table>
    <div class="foot">Automatisch erzeugtes Dokument &middot; UltraGold Portal</div>
</body></html>
HTML;
    }

    private function row(string $label, string $valueHtml): string
    {
        return '<tr><td class="lbl">' . $this->esc($label) . '</td><td>' . $valueHtml . '</td></tr>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
