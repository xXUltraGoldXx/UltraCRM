<?php

namespace App\Service;

use App\Entity\MailSetting;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Baut den Versandweg zur Laufzeit aus der Mandanten-Einstellung.
 *
 * Bewusst kein global konfigurierter Mailer: sonst wuerden alle Mandanten
 * ueber denselben Absender verschicken.
 */
final class MailerFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function findSetting(?Tenant $tenant): ?MailSetting
    {
        if ($tenant === null) {
            return null;
        }

        // Der Mandantenfilter greift hier nicht immer (Cron laeuft ohne
        // angemeldeten Benutzer), deshalb ausdruecklich nach tenant filtern.
        return $this->em->getRepository(MailSetting::class)
            ->findOneBy(['tenant' => $tenant, 'active' => true]);
    }

    /** @throws \RuntimeException wenn kein brauchbarer Versandweg hinterlegt ist */
    public function build(MailSetting $setting): MailerInterface
    {
        $host = $setting->getProvider() === 'mailjet'
            ? ($setting->getHost() ?: 'in-v3.mailjet.com')
            : $setting->getHost();

        if (!$host) {
            throw new \RuntimeException('Es ist kein Server für den Versand hinterlegt.');
        }

        $passwort = $this->secretBox->decrypt($setting->getSecret());
        if ($setting->getUsername() && $passwort === null) {
            throw new \RuntimeException(
                'Das gespeicherte Passwort konnte nicht gelesen werden. '
                . 'Bitte erneut eintragen (das passiert, wenn sich der Anwendungsschlüssel geändert hat).'
            );
        }

        $dsn = sprintf(
            'smtp://%s%s:%d',
            $setting->getUsername()
                ? rawurlencode($setting->getUsername()) . ':' . rawurlencode((string) $passwort) . '@'
                : '',
            $host,
            $setting->getPort() ?: 587,
        );

        return new Mailer(Transport::fromDsn($dsn));
    }

    public function absender(MailSetting $setting): string
    {
        return sprintf('%s <%s>', $setting->getFromName(), $setting->getFromAddress());
    }

    /**
     * Verschickt und gibt bei Misserfolg eine verstaendliche Meldung zurueck,
     * statt eine Exception nach aussen durchzureichen.
     */
    public function send(MailSetting $setting, string $an, string $betreff, string $text): ?string
    {
        try {
            $mail = (new Email())
                ->from($this->absender($setting))
                ->to($an)
                ->subject($betreff)
                ->text($text);

            $this->build($setting)->send($mail);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
