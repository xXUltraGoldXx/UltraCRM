<?php

namespace App\Doctrine;

use App\Entity\ChangeLog;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Deal;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records changes to contacts, companies and deals in the change log.
 *
 * Implemented in onFlush rather than postUpdate: only there is the
 * changeset available that Doctrine has already computed — a manual
 * "before/after" comparison would duplicate that work and miss edge
 * cases.
 *
 * Fields that change for technical reasons (updatedAt) or whose content
 * is itself sensitive (passwords, mail credentials) are deliberately NOT
 * logged — a change log must not become a side channel for secrets.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ChangeLogSubscriber
{
    private const TYPEN = [
        Contact::class => 'contact',
        Company::class => 'company',
        Deal::class => 'deal',
    ];

    /** Fields that never end up in the change log. */
    private const NICHT_PROTOKOLLIEREN = [
        'password', 'plainPassword', 'secret', 'plainSecret',
        'confirmToken', 'updatedAt', 'customData',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ChangeLog::class);

        $benutzer = $this->security->getUser();
        $name = $benutzer instanceof User ? $benutzer->getUsername() : null;

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $typ = self::TYPEN[$entity::class] ?? null;
            if ($typ === null || !method_exists($entity, 'getId') || $entity->getId() === null) {
                continue;
            }

            foreach ($uow->getEntityChangeSet($entity) as $feld => [$alt, $neu]) {
                if (in_array($feld, self::NICHT_PROTOKOLLIEREN, true)) {
                    continue;
                }

                $altText = $this->alsText($alt);
                $neuText = $this->alsText($neu);
                if ($altText === $neuText) {
                    continue;
                }

                $eintrag = new ChangeLog($typ, $entity->getId(), $feld, $altText, $neuText, $name);
                if ($entity instanceof TenantOwnedInterface) {
                    $eintrag->setTenant($entity->getTenant());
                }

                // Inside onFlush, new entities must be scheduled manually
                // — a later flush() would no longer pick them up.
                $em->persist($eintrag);
                $uow->computeChangeSet($metadata, $eintrag);
            }
        }
    }

    private function alsText(mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        if (is_bool($wert)) {
            return $wert ? 'ja' : 'nein';
        }

        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('d.m.Y H:i');
        }

        if (is_object($wert)) {
            // Make relations human-readable instead of storing an object id.
            foreach (['getName', 'getTitle', 'getDisplayName', 'getUsername'] as $methode) {
                if (method_exists($wert, $methode)) {
                    return (string) $wert->$methode();
                }
            }

            return method_exists($wert, 'getId') ? '#' . $wert->getId() : null;
        }

        if (is_array($wert)) {
            return mb_substr(json_encode($wert, \JSON_UNESCAPED_UNICODE) ?: '', 0, 500);
        }

        return mb_substr((string) $wert, 0, 500);
    }
}
