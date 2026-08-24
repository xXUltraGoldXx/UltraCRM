<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Makes the permission catalog available for `is_granted('PERM',
 * 'contacts.view')` — in controllers as well as API Platform attributes.
 *
 * Why a voter instead of an expression per operation: the rule that
 * "manage implies view" would otherwise have to be repeated in every
 * single expression. That was the exact source of an earlier bug — a
 * staff member with `activities.manage` got a 403 on read, because the
 * expression only checked `activities.view`. Here the logic lives in one
 * place and is covered by PermissionsTest.
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
