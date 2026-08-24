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

        // Der Mandantenfilter steht bei einer anonymen Anfrage (oeffentlicher
        // Lead-Endpunkt, Cron ohne angemeldeten Benutzer) auf "zu" (tenant_id
        // = 0, TenantFilterSubscriber) — er wird NICHT einfach ignoriert, nur
        // weil hier zusaetzlich explizit nach $tenant gefiltert wird. Beide
        // Bedingungen werden von Doctrine UND-verknuepft, also
        // "tenant = 3 AND tenant_id = 0", was nie zutrifft. Ohne dieses
        // Abschalten fand der oeffentliche Lead-Endpunkt seinen eigenen
        // Versandweg nie — live entdeckt: Testmail ueber /api/mail/test (mit
        // angemeldetem Benutzer, Filter korrekt auf den echten Mandanten)
        // gelang, dieselbe Einstellung blieb aus dem anonymen Lead-Endpunkt
        // heraus unsichtbar (Analyse.md C44).
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
                // enable() nach disable() liefert eine NEUE Filterinstanz
                // ohne Parameter zurueck (C32) — deshalb hier erneut setzen,
                // nicht einfach enable() allein aufrufen.
                $filters->enable('tenant_filter')->setParameter('tenant_id', '0');
            }
        }
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

        $this->pruefeHost($host);

        $passwort = $this->secretBox->decrypt($setting->getSecret());
        if ($setting->getUsername() && $passwort === null) {
            // Zwei sehr verschiedene Ursachen, zwei verschiedene Meldungen:
            // gar kein Passwort hinterlegt ist der Normalfall beim Einrichten,
            // ein unlesbares deutet auf einen gewechselten Anwendungsschlüssel.
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
     * Verhindert, dass ueber die Mail-Einstellung interne Adressen
     * angesprochen werden (SSRF, Review-Befund 45). Ein Mailserver steht im
     * Internet — Loopback, private Netze und Cloud-Metadaten haben dort
     * nichts zu suchen.
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

        // Auch ein Name kann auf eine interne Adresse zeigen — deshalb wird
        // aufgeloest und das Ergebnis geprueft.
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
