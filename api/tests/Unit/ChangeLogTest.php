<?php

namespace App\Tests\Unit;

use App\Entity\ChangeLog;
use PHPUnit\Framework\TestCase;

/** Das Protokoll haelt fest, was passiert ist — und laesst sich nicht aendern. */
final class ChangeLogTest extends TestCase
{
    public function testEintragHaeltAlleAngaben(): void
    {
        $e = new ChangeLog('contact', 42, 'status', 'neu', 'kunde', 'alexander');

        self::assertSame('contact', $e->getSubjectType());
        self::assertSame(42, $e->getSubjectId());
        self::assertSame('status', $e->getField());
        self::assertSame('neu', $e->getOldValue());
        self::assertSame('kunde', $e->getNewValue());
        self::assertSame('alexander', $e->getChangedBy());
        self::assertNotNull($e->getChangedAt());
    }

    public function testKeineSetterFuerDenInhalt(): void
    {
        // Ein Protokoll, dessen Eintraege sich nachtraeglich aendern lassen,
        // belegt nichts. Ausser dem Mandanten (technisch noetig) gibt es
        // deshalb keine Setter.
        foreach (['setField', 'setOldValue', 'setNewValue', 'setChangedBy', 'setChangedAt', 'setSubjectId'] as $methode) {
            self::assertFalse(
                method_exists(ChangeLog::class, $methode),
                $methode . ' darf es nicht geben — das Protokoll ist unveraenderlich.'
            );
        }
    }

    public function testLeereWerteBleibenNull(): void
    {
        $e = new ChangeLog('deal', 1, 'value', null, '1200.00', null);

        self::assertNull($e->getOldValue());
        self::assertSame('1200.00', $e->getNewValue());
        self::assertNull($e->getChangedBy(), 'Ein Systemvorgang ohne Benutzer bleibt ohne Namen.');
    }
}
