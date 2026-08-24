<?php

namespace App\Service;

/**
 * Encrypts credentials that have to be stored in the database (SMTP
 * passwords, API keys).
 *
 * Why this exists at all: a tenant admin may set their own password, but
 * nobody afterwards — not even someone reading the database directly —
 * should see it in plain text. Encrypted rather than hashed, because the
 * value is needed again to actually send mail.
 *
 * The key is derived from APP_SECRET. If APP_SECRET changes, the stored
 * credentials become unreadable — that is intentional and documented.
 */
final class SecretBox
{
    private string $key;

    public function __construct(string $appSecret)
    {
        // sodium expects exactly 32 bytes.
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

        // A decryption failure means either the wrong key or tampered
        // data. Neither should silently pass through as an empty password.
        return $klartext === false ? null : $klartext;
    }
}
