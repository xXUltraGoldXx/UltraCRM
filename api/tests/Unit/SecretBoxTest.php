<?php

namespace App\Tests\Unit;

use App\Service\SecretBox;
use PHPUnit\Framework\TestCase;

/** Zugangsdaten muessen lesbar zurueckkommen — und nur mit dem richtigen Schluessel. */
final class SecretBoxTest extends TestCase
{
    public function testVerschluesselnUndEntschluesseln(): void
    {
        $box = new SecretBox('geheimnis-eins');
        $chiffre = $box->encrypt('mein-smtp-passwort');

        self::assertNotSame('mein-smtp-passwort', $chiffre);
        self::assertStringNotContainsString('mein-smtp-passwort', (string) $chiffre);
        self::assertSame('mein-smtp-passwort', $box->decrypt($chiffre));
    }

    public function testFalscherSchluesselLiefertNullStattMuell(): void
    {
        $chiffre = (new SecretBox('geheimnis-eins'))->encrypt('passwort');

        self::assertNull(
            (new SecretBox('anderes-geheimnis'))->decrypt($chiffre),
            'Ein falscher Schluessel darf kein leeres Passwort vortaeuschen.'
        );
    }

    public function testLeereWerteBleibenLeer(): void
    {
        $box = new SecretBox('geheimnis');

        self::assertNull($box->encrypt(null));
        self::assertNull($box->encrypt(''));
        self::assertNull($box->decrypt(null));
        self::assertNull($box->decrypt('kein-gueltiger-chiffretext'));
    }

    public function testZweimalVerschluesselnErgibtVerschiedeneChiffren(): void
    {
        $box = new SecretBox('geheimnis');

        // Gleicher Klartext, anderer Nonce — sonst waere aus der Datenbank
        // ablesbar, welche Mandanten dasselbe Passwort benutzen.
        self::assertNotSame($box->encrypt('gleich'), $box->encrypt('gleich'));
    }
}
