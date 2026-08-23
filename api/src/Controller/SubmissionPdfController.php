<?php

namespace App\Controller;

use App\Entity\FormSubmission;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubmissionPdfController extends AbstractController
{
    #[Route('/api/form_submissions/{id}/pdf', name: 'submission_pdf', methods: ['GET'])]
    public function pdf(FormSubmission $submission): Response
    {
        // Fund beim Bau von HolidayRequestPdfController (Paket 1, Punkt 1):
        // diese Route ist ein PLAIN Symfony-Controller, die submissions.view/
        // manage/Eigentuemer-Security-Expression von FormSubmission::Get
        // (Verbesserungs-Durchlauf Punkt 1) greift hier NICHT automatisch --
        // ohne diesen Check waere das PDF fremder Eintraege weiterhin fuer
        // jeden Authentifizierten erreichbar gewesen, obwohl der normale
        // GET-Weg schon gesperrt ist. Nachtraeglich ergaenzt, 1:1 dieselbe Regel.
        $this->assertReadable($submission);

        $html = $this->renderHtml($submission);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $name = $this->slug($submission->getTemplateName() ?: 'formular');
        $filename = sprintf('%s-%d.pdf', $name, $submission->getId());

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
        ]);
    }

    private function assertReadable(FormSubmission $submission): void
    {
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $canSee = $isAdmin || ($user instanceof User && (
            in_array('submissions.view', $user->getPermissions(), true)
            || in_array('submissions.manage', $user->getPermissions(), true)
        ));
        $isOwner = $user instanceof User
            && $submission->getCreatedBy()
            && $submission->getCreatedBy()->getId() === $user->getId();

        if (!$canSee && !$isOwner) {
            throw $this->createAccessDeniedException('Kein Zugriff auf diesen Eintrag.');
        }
    }

    private function renderHtml(FormSubmission $submission): string
    {
        $template = $submission->getTemplate();
        $schema = $template?->getSchema() ?? [];
        $data = $submission->getData();
        $customer = $submission->getCustomer();

        // Platzhalter für Kopf/Felder
        $placeholders = [
            'customer.company' => $customer?->getCompany() ?? '',
            'customer.city' => $customer?->getCity() ?? '',
            'date' => (new \DateTimeImmutable())->format('d.m.Y'),
        ];
        $replace = function (string $text) use ($placeholders): string {
            return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($placeholders) {
                return $placeholders[$m[1]] ?? $m[0];
            }, $text);
        };

        $rows = '';
        foreach ($schema as $field) {
            $type = $field['type'] ?? 'text';
            $label = $this->esc($field['label'] ?? '');
            $value = $data[$field['id']] ?? null;

            if ($type === 'heading') {
                $rows .= '<tr><td colspan="2" class="section">' . $this->esc($replace($field['label'] ?? '')) . '</td></tr>';
                continue;
            }

            $rendered = $this->renderValue($type, $value, $customer);
            $rows .= '<tr><td class="lbl">' . $label . '</td><td class="val">' . $rendered . '</td></tr>';
        }

        $title = $this->esc($submission->getTemplateName() ?: 'Formular');
        $meta = sprintf(
            'Erstellt am %s%s%s',
            $submission->getCreatedAt()->format('d.m.Y H:i'),
            $submission->getCreatedBy() ? ' von ' . $this->esc($submission->getCreatedBy()->getDisplayName()) : '',
            $customer ? ' &middot; Kunde: ' . $this->esc($customer->getCompany()) : ''
        );

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
    td.section { background: #f3f5f9; font-weight: bold; font-size: 13px; border-bottom: 2px solid #3b82f6; padding-top: 12px; }
    .sig-box { border: 1px dashed #aaa; height: 70px; border-radius: 6px; }
    .parts { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .parts th { background: #f3f5f9; text-align: left; padding: 5px 8px; font-size: 10px; border: 1px solid #ddd; }
    .parts td { padding: 5px 8px; border: 1px solid #ddd; font-size: 11px; }
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

    private function renderValue(string $type, mixed $value, $customer): string
    {
        if ($type === 'signature') {
            return '<div class="sig-box"></div>';
        }
        if ($type === 'customer') {
            if ($customer) {
                return $this->esc($customer->getCompany() . ($customer->getCity() ? ', ' . $customer->getCity() : ''));
            }
            return '<span class="empty">—</span>';
        }
        if ($type === 'checkbox') {
            return $value ? 'Ja' : 'Nein';
        }
        if ($type === 'spareparts') {
            if (!is_array($value) || !count($value)) {
                return '<span class="empty">Keine Ersatzteile</span>';
            }
            $body = '';
            foreach ($value as $row) {
                $body .= '<tr><td>' . $this->esc($row['name'] ?? '') . '</td><td>'
                    . $this->esc($row['snOld'] ?? '') . '</td><td>'
                    . $this->esc($row['snNew'] ?? '') . '</td></tr>';
            }
            return '<table class="parts"><thead><tr><th>Bezeichnung</th><th>Alte SN</th><th>Neue SN</th></tr></thead><tbody>'
                . $body . '</tbody></table>';
        }

        if ($value === null || $value === '') {
            return '<span class="empty">—</span>';
        }

        return nl2br($this->esc((string) $value));
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9]+/', '-', $s);

        return strtolower(trim($s, '-')) ?: 'formular';
    }
}
