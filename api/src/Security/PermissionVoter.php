<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Macht den Rechtekatalog fuer `is_granted('PERM', 'contacts.view')`
 * verfuegbar — in Controllern wie in den API-Platform-Attributen.
 *
 * Warum ein Voter und kein Ausdruck je Operation: die Regel "manage
 * schliesst view ein" muesste sonst in jedem einzelnen Ausdruck wiederholt
 * werden. Beim ersten Versuch war genau das die Fehlerquelle — ein
 * Mitarbeiter mit `activities.manage` bekam beim Lesen 403, weil der
 * Ausdruck nur auf `activities.view` prueft. Hier gilt die Logik einmal und
 * ist in PermissionsTest abgesichert.
 */
final class PermissionVoter extends Voter
{
    public const ATTRIBUT = 'PERM';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUT && is_string($subject);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        return Permissions::hat($user instanceof User ? $user : null, (string) $subject);
    }
}
