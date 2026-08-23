<?php

namespace App\Controller;

use App\Security\Permissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert den Rechtekatalog fuer die Benutzerverwaltung.
 *
 * Der Katalog steht im PHP-Code (Permissions::KATALOG) und wird NICHT im
 * Frontend zweitgepflegt — sonst laufen beide Listen auseinander und ein
 * Recht existiert in der Oberflaeche, das die API gar nicht kennt.
 */
final class PermissionCatalogController extends AbstractController
{
    #[Route('/api/permissions', name: 'permission_catalog', methods: ['GET'])]
    public function katalog(): Response
    {
        // Ausdrückliche Prüfung statt Attribut: IsGranted-Attribute werden in
        // diesen Controllern nicht ausgewertet (Analyse.md C13). Beim ersten
        // Versuch lieferte der Endpunkt jedem angemeldeten Benutzer 200.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $gruppen = [];
        foreach (Permissions::KATALOG as $gruppe => $rechte) {
            $gruppen[] = [
                'gruppe' => $gruppe,
                'rechte' => array_map(
                    static fn (string $schluessel, string $text) => ['schluessel' => $schluessel, 'text' => $text],
                    array_keys($rechte),
                    array_values($rechte),
                ),
            ];
        }

        return new JsonResponse(['gruppen' => $gruppen]);
    }
}
