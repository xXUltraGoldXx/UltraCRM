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
 * Builds the mail transport at runtime from the tenant's settings.
 *
 * Deliberately no globally configured mailer: otherwise all tenants would
 * send through the same sender.
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

        // For an anonymous request (public lead endpoint, cron without a
        // logged-in user), the tenant filter defaults to closed (tenant_id
        // = 0, see TenantFilterSubscriber) — it is NOT simply ignored just
        // because $tenant is also explicitly filtered on here. Doctrine
        // ANDs both conditions together, i.e. "tenant = 3 AND tenant_id =
        // 0", which never matches. Without disabling it, the public lead
        // endpoint could never find its own mail settings — discovered in
        // production: a test mail via /api/mail/test (with a logged-in
        // user, filter correctly set to the real tenant) worked, while the
        // same setting stayed invisible from the anonymous lead endpoint.
        $filters = $this->em->getFilters();
        $warAn = $filters->isEnabled('tenant_filter');
        if ($warAn) {
            $filters->disable('tenant_filter');
        }

        try {
            return $this->em->getRepository(MailSetting::class)
                ->findOneBy(['tenant' => $tenant, 'active' => true]);
        } finally {
            if ($warAn) {
                // enable() after disable() returns a NEW filter instance
                // with no parameter set — so it has to be set again here,
                // not just call enable() alone.
                $filters->enable('tenant_filter')->setParameter('tenant_id', '0');
            }
        }
    }

    /** @throws \RuntimeException if no usable mail transport is configured */
    public function build(MailSetting $setting): MailerInterface
    {
        $host = $setting->getProvider() === 'mailjet'
            ? ($setting->getHost() ?: 'in-v3.mailjet.com')
            : $setting->getHost();

        if (!$host) {
            throw new \RuntimeException('Es ist kein Server für den Versand hinterlegt.');
        }

        $this->pruefeHost($host);

        $passwort = $this->secretBox->decrypt($setting->getSecret());
        if ($setting->getUsername() && $passwort === null) {
            // Two very different causes, two different messages: no
            // password stored at all is the normal case while setting
            // things up; an unreadable one points to a changed
            // application secret.
            throw new \RuntimeException(
                $setting->getSecret() === null || $setting->getSecret() === ''
                    ? 'Für diesen Benutzernamen ist kein Passwort hinterlegt. Bitte eines eintragen.'
                    : 'Das gespeicherte Passwort konnte nicht entschlüsselt werden. '
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

    /**
     * Prevents the mail settings from being used to reach internal
     * addresses (SSRF). A mail server lives on the public internet —
     * loopback addresses, private networks, and cloud metadata endpoints
     * have no business being targeted here.
     */
    private function pruefeHost(string $host): void
    {
        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            $oeffentlich = filter_var(
                $host,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE
            );
            if ($oeffentlich === false) {
                throw new \RuntimeException('Diese Serveradresse ist nicht zulässig.');
            }

            return;
        }

        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
            throw new \RuntimeException('Der Servername sieht nicht wie ein gültiger Hostname aus.');
        }

        // A hostname can resolve to an internal address too — so it's
        // resolved and the result is checked.
        foreach ((array) gethostbynamel($host) as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Dieser Server verweist auf eine interne Adresse und ist nicht zulässig.');
            }
        }
    }

    public function absender(MailSetting $setting): string
    {
        return sprintf('%s <%s>', $setting->getFromName(), $setting->getFromAddress());
    }

    /**
     * Sends the mail and returns a readable message on failure, instead
     * of letting an exception propagate outward.
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
