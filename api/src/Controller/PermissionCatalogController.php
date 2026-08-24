<?php

namespace App\Controller;

use App\Security\Permissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns the permission catalog for user management.
 *
 * The catalog lives in PHP code (Permissions::KATALOG) and is NOT
 * maintained a second time in the frontend — otherwise the two lists
 * would drift apart and a permission could exist in the UI that the API
 * does not even know about.
 */
final class PermissionCatalogController extends AbstractController
{
    #[Route('/api/permissions', name: 'permission_catalog', methods: ['GET'])]
    public function katalog(): Response
    {
        // Explicit check instead of an attribute: #[IsGranted] attributes
        // are not evaluated on these controllers. The first attempt at
        // this endpoint returned 200 for every logged-in user.
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

        // Sections for the permission groups. The catalog is the single
        // source of truth: the frontend builds its toggles from it
        // instead of maintaining the list a second time. A section only
        // shows the levels that genuinely exist for it — a reports
        // section, for example, cannot be "written to".
        $bereiche = [];
        foreach (Permissions::BEREICHE as $schluessel => $stufen) {
            $bereiche[] = [
                'schluessel' => $schluessel,
                'name' => Permissions::BEREICH_NAMEN[$schluessel] ?? $schluessel,
                'stufen' => $stufen,
            ];
        }

        return new JsonResponse([
            'gruppen' => $gruppen,
            'bereiche' => $bereiche,
            'stufenNamen' => [
                'lesen' => 'Lesen',
                'schreiben' => 'Schreiben',
                'loeschen' => 'Löschen',
            ],
        ]);
    }
}
