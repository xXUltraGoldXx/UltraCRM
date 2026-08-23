<?php

namespace App\Service;

/**
 * Verschluesselt Zugangsdaten, die in der Datenbank liegen muessen
 * (SMTP-Passwoerter, API-Schluessel).
 *
 * Warum ueberhaupt: Ein Mandanten-Admin darf sein eigenes Passwort setzen,
 * aber niemand — auch kein Datenbank-Leser — soll es hinterher im Klartext
 * sehen. Verschluesselt statt gehasht, weil der Wert zum Versenden wieder
 * gebraucht wird.
 *
 * Schluessel wird aus APP_SECRET abgeleitet. Faellt APP_SECRET, sind die
 * gespeicherten Zugangsdaten unlesbar — das ist gewollt und in der Doku
 * vermerkt.
 */
final class SecretBox
{
    private string $key;

    public function __construct(string $appSecret)
    {
        // sodium erwartet exakt 32 Byte.
        $this->key = hash('sha256', 'mail-secret|' . $appSecret, true);
    }

    public function encrypt(?string $klartext): ?string
    {
        if ($klartext === null || $klartext === '') {
            return null;
        }

        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($klartext, $nonce, $this->key));
    }

    public function decrypt(?string $gespeichert): ?string
    {
        if ($gespeichert === null || $gespeichert === '') {
            return null;
        }

        $roh = base64_decode($gespeichert, true);
        if ($roh === false || strlen($roh) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($roh, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($roh, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $klartext = sodium_crypto_secretbox_open($chiffre, $nonce, $this->key);

        // Falsch entschluesselt heisst: falscher Schluessel oder manipulierte
        // Daten. Beides darf nicht still als leeres Passwort durchgehen.
        return $klartext === false ? null : $klartext;
    }
}
