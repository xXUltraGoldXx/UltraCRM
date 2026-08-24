# UltraCRM

Ein mandantenfähiges CRM für Vertrieb und Leadgenerierung — deutschsprachig,
DSGVO-orientiert, auf eigenem Server betrieben.

Läuft unter <https://crm.ultragold.de>.

## Was es kann

Kontakte und Firmen mit Ansprechpartnern und Abteilungen, eine Vertriebs-
pipeline mit frei konfigurierbaren Phasen, Aktivitäten und Wiedervorlagen,
öffentliche Lead-Formulare mit Double-Opt-in, Auswertung, Import und Export
(CSV/XLSX), Dublettenerkennung, Änderungs- und Löschprotokoll, Auskunft und
Löschung nach DSGVO, frei definierbare Zusatzfelder und ein Rechtesystem je
Mitarbeiter.

## Aufbau

```
api/        Symfony 8 + API Platform 4, JWT-Anmeldung, MySQL
frontend/   Vue 3 + Vite + Pinia, Oberfläche nach Apple HIG
```

Ausgeliefert wird von OpenResty (1Panel-Container `1Panel-openresty-dU2w`),
Konfiguration unter
`/opt/1panel/apps/openresty/openresty/conf/conf.d/crm.ultragold.de.conf`:
`frontend/dist` als Wurzel, `/api` an PHP-FPM (127.0.0.1:9000).

## Die drei Regeln, die nicht gebrochen werden dürfen

Sie stehen hier, weil in dieser Reihenfolge Fehler passiert sind — jeder
davon ist in `Analyse.md` mit Belegstelle dokumentiert.

**1. Mandantentrennung wird geprüft, nicht angenommen.**
Der Doctrine-Filter `tenant_filter` hängt an allen Entities mit
`TenantOwnedInterface` und steht im Zweifel auf „zu" (Mandant 0). Aber: Für
`ROLE_SUPERADMIN` ist er abgeschaltet, und `User` ist absichtlich
ausgenommen, weil er sonst die Anmeldung aussperren würde. Wo eine Regel
eine Ausnahme hat, muss an ihrer Stelle etwas anderes stehen —
`MandantReferenz` für Verweise zwischen Datensätzen, `UserTenantExtension`
für Benutzerabfragen.

**2. Jede API-Ressource braucht eine ausdrückliche Sicherheitsangabe.**
Eine fehlende `security:`-Angabe sieht aus wie „nichts Besonderes nötig" und
ist eine offene Tür. Rechte richten sich nach der *Wirkung*, nicht nach dem
Ort im Code: Was löscht, verlangt `ROLE_ADMIN` — egal wie der Endpunkt
heißt. In eigenen Controllern wirkt `#[IsGranted]` **nicht**; dort gehört
`denyAccessUnlessGranted()` in den Methodenrumpf.

**3. Schutzmechanismen fallen in die sichere Richtung.**
Ein Kontakt ohne bestätigte Einwilligung ist nicht bewerbbar. Ein Widerruf
gewinnt immer. Eine unbekannte Phasenart gilt als *offen*, nicht als
abgeschlossen. Zusammengehörige Felder eines Schutzmechanismus werden nie
einzeln kopiert.

## Entwickeln

```bash
# Tests (108, davon 61 Integrationstests gegen eine echte Datenbank)
cd api && vendor/bin/phpunit

# Testdatenbank einmalig einrichten
mysql -uroot -e "CREATE DATABASE crm_test CHARACTER SET utf8mb4"
# api/.env.test.local anlegen (DATABASE_URL mit Datenbanknamen "crm",
# Doctrine hängt "_test" an; dazu JWT_PASSPHRASE aus .env.local)
APP_ENV=test php bin/console doctrine:schema:create
```

Die Integrationstests schicken echte Anfragen durch den Kernel — Routing,
Rechte, Serializer und Mandantenfilter laufen wie im Betrieb. Sie leeren
zwischen den Fällen alle Tabellen und brechen ab, wenn der Datenbankname
nicht auf `_test` endet.

**Jede Korrektur bekommt eine Gegenprobe:** Fehler wieder einbauen, Suite
muss rot werden. Ein Test, der den fraglichen Code nicht ausführt, ist kein
Nachweis.

## Ausliefern

```bash
# Frontend
cd frontend && npm run build && chown -R www-data:www-data dist/

# Backend — der Reload ist Pflicht, cache:clear allein genügt nicht (OPcache)
cd api && php bin/console cache:clear --env=prod && systemctl reload php8.5-fpm
```

Die Ausgabe von `cache:clear` nie unterdrücken: Ein Fatal Error darin bleibt
sonst unbemerkt, und die Anwendung läuft mit dem alten Cache weiter.

## Betrieb

```bash
# Migration der Phasen auf einem weiteren Server nachziehen
php bin/console app:phasen:nachziehen --probe   # zeigt nur an
php bin/console app:phasen:nachziehen
```

Datenbanksicherung läuft serverweit nachts um 03:15 (`/usr/local/bin/db-backup.sh`).

## Arbeitsdokumente

| Datei | Inhalt |
|---|---|
| `Context.md` | Stand, Architektur, nächste Schritte — Einstieg für die Fortsetzung |
| `TODO.md` | Arbeitspakete, offene Entscheidungen, Fragen an Alexander |
| `Analyse.md` | Jeder gefundene Fehler mit Belegstelle, Nachweis und Lehre |
