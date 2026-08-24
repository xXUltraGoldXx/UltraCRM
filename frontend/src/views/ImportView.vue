<script setup>
import { computed, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';
import { SICHERHEIT_LABEL } from '../labels.js';

// Reihenfolge und Beschriftung der Zielfelder — "Ignorieren" steht oben,
// weil es der haeufigste Vorschlag fuer unbekannte Spalten ist.
const FELD_OPTIONEN = [
    { value: 'ignore', label: 'Ignorieren' },
    { value: 'firstName', label: 'Vorname' },
    { value: 'lastName', label: 'Nachname' },
    { value: 'email', label: 'E-Mail' },
    { value: 'phone', label: 'Telefon' },
    { value: 'company', label: 'Firma' },
    { value: 'position', label: 'Position' },
    { value: 'department', label: 'Abteilung' },
];
const FELD_LABEL = Object.fromEntries(FELD_OPTIONEN.map((f) => [f.value, f.label]));

// Entscheidung je Zeile in der Abgleich-Vorschau.
const ENTSCHEIDUNG_OPTIONEN = [
    { value: 'neu', label: 'Neu anlegen' },
    { value: 'aktualisieren', label: 'Ergänzen' },
    { value: 'ueberspringen', label: 'Überspringen' },
];

const SCHRITTE = [
    { id: 'auswahl', label: 'Datei' },
    { id: 'zuordnung', label: 'Zuordnung' },
    { id: 'vorschau', label: 'Vorschau' },
    { id: 'bericht', label: 'Bericht' },
];

const MAX_DATEIGROESSE = 5 * 1024 * 1024; // 5 MB, siehe TODO.md A11

const schritt = ref('auswahl');
const datei = ref(null);
const laedt = ref(false);
const fehler = ref('');

const kopf = ref([]);
const previewRows = ref([]);
const totalRows = ref(0);
const mapping = ref([]);

// Ergebnis von /import/preview (Abgleich gegen den Bestand) und die
// Entscheidung des Anwenders je Zeile — Schluessel ist die Zeilennummer
// als String, wie es /import/execute in "decisions" erwartet.
const abgleich = ref(null);
const entscheidungen = ref({});
// Nur gesetzt, wenn die Vorschau tatsaechlich durchlaufen wurde — sonst
// werden beim Uebernehmen keine decisions mitgeschickt (Punkt 7).
const vorschauGenutzt = ref(false);

const bericht = ref(null);

const schrittIndex = computed(() => SCHRITTE.findIndex((s) => s.id === schritt.value));

function dateiGewaehlt(e) {
    const f = e.target.files?.[0] || null;
    fehler.value = '';
    if (f && f.size > MAX_DATEIGROESSE) {
        fehler.value = 'Die Datei ist zu groß (maximal 5 MB).';
        datei.value = null;
        e.target.value = '';
        return;
    }
    datei.value = f;
}

async function analysieren() {
    if (!datei.value) {
        fehler.value = 'Bitte eine Datei auswählen.';
        return;
    }
    laedt.value = true;
    fehler.value = '';
    try {
        const form = new FormData();
        form.append('file', datei.value);
        const { data } = await api.post('/import/analyze', form);
        kopf.value = data.headers;
        previewRows.value = data.previewRows;
        totalRows.value = data.totalRows;
        mapping.value = data.suggestions;
        schritt.value = 'zuordnung';
    } catch (e) {
        fehler.value = e.response?.data?.error || 'Die Datei konnte nicht gelesen werden.';
    } finally {
        laedt.value = false;
    }
}

function spalteWert(zeile, feld) {
    const idx = mapping.value.indexOf(feld);
    if (idx === -1) return '';
    return (zeile[idx] ?? '').toString().trim();
}

const nachnameFehltIn = computed(() => previewRows.value.filter((z) => spalteWert(z, 'lastName') === '').length);

function zurZuordnung() {
    schritt.value = 'zuordnung';
}

// Zeilen mit mindestens einem Treffer im Bestand stehen im Fokus der
// Vorschau; Zeilen ohne Treffer werden laut Vorgabe nicht aufgeblaeht
// (Punkt 5) und nur als Summe gezeigt.
// Zu entscheiden sind Zeilen mit Bestandstreffer UND Zeilen, die schon
// weiter oben in derselben Datei stehen — beide braucht der Anwender vor
// Augen, der Rest waere nur Laenge.
const zeilenMitTreffer = computed(
    () => abgleich.value?.rows.filter((z) => z.treffer?.length || z.dateiDublette) ?? []
);
const zeilenOhneTreffer = computed(
    () => abgleich.value?.rows.filter((z) => !z.treffer?.length && !z.dateiDublette) ?? []
);

function entscheidungFuer(zeile) {
    return entscheidungen.value[zeile.row] ?? { action: zeile.vorschlag };
}

function aktionSetzen(zeile, action) {
    const bisherige = entscheidungFuer(zeile);
    entscheidungen.value[zeile.row] = {
        action,
        contactId: action === 'aktualisieren' ? (bisherige.contactId ?? zeile.treffer[0]?.id) : undefined,
    };
}

function kontaktWaehlen(zeile, id) {
    entscheidungen.value[zeile.row] = { action: 'aktualisieren', contactId: id };
}

// Sammelaktion: bei jeder Zeile, deren bester Treffer sicher ist (gleiche
// E-Mail), automatisch ergaenzen statt einzeln durchklicken zu muessen.
function alleSicherErgaenzen() {
    for (const zeile of zeilenMitTreffer.value) {
        if (zeile.dateiDublette) continue; // steht schon oben in der Datei
        if (zeile.treffer[0]?.sicherheit === 'sicher') {
            entscheidungen.value[zeile.row] = { action: 'aktualisieren', contactId: zeile.treffer[0].id };
        }
    }
}
function alleAlsNeuAnlegen() {
    for (const zeile of abgleich.value?.rows ?? []) {
        entscheidungen.value[zeile.row] = { action: 'neu', contactId: undefined };
    }
}

async function zurVorschau() {
    if (!datei.value) return;
    laedt.value = true;
    fehler.value = '';
    try {
        const form = new FormData();
        form.append('file', datei.value);
        form.append('mapping', JSON.stringify(mapping.value));
        const { data } = await api.post('/import/preview', form);
        abgleich.value = data;
        entscheidungen.value = Object.fromEntries(
            data.rows.map((z) => [z.row, {
                action: z.vorschlag,
                contactId: z.vorschlag === 'aktualisieren' ? z.treffer[0]?.id : undefined,
            }])
        );
        vorschauGenutzt.value = true;
        schritt.value = 'vorschau';
    } catch (e) {
        fehler.value = e.response?.data?.error || 'Die Vorschau konnte nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}

async function uebernehmen(mitVorschau) {
    if (!datei.value) return;
    laedt.value = true;
    fehler.value = '';
    try {
        const form = new FormData();
        form.append('file', datei.value);
        form.append('mapping', JSON.stringify(mapping.value));
        if (mitVorschau && vorschauGenutzt.value) {
            const decisions = Object.fromEntries(
                Object.entries(entscheidungen.value).map(([row, e]) => [
                    row,
                    e.action === 'aktualisieren' ? { action: e.action, contactId: e.contactId } : { action: e.action },
                ])
            );
            form.append('decisions', JSON.stringify(decisions));
        }
        const { data } = await api.post('/import/execute', form);
        bericht.value = data;
        schritt.value = 'bericht';
    } catch (e) {
        fehler.value = e.response?.data?.error || 'Der Import konnte nicht ausgeführt werden.';
    } finally {
        laedt.value = false;
    }
}

function neuerImport() {
    schritt.value = 'auswahl';
    datei.value = null;
    kopf.value = [];
    previewRows.value = [];
    mapping.value = [];
    abgleich.value = null;
    entscheidungen.value = {};
    vorschauGenutzt.value = false;
    bericht.value = null;
    fehler.value = '';
}
</script>

<template>
    <div class="stack">
        <header>
            <h2 class="t-large-title">Import</h2>
            <p class="t-subhead">Kontakte aus einer CSV- oder Excel-Datei anderer Systeme übernehmen.</p>
        </header>

        <!-- Schrittanzeige -->
        <div class="schritte">
            <div v-for="(s, i) in SCHRITTE" :key="s.id" class="schritt-punkt" :class="{ aktiv: i === schrittIndex, fertig: i < schrittIndex }">
                <span class="schritt-nr">{{ i + 1 }}</span>
                <span class="t-footnote">{{ s.label }}</span>
            </div>
        </div>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <!-- Schritt 1: Datei waehlen -->
        <UiCard v-if="schritt === 'auswahl'" class="stack">
            <p class="t-headline">Datei wählen</p>
            <p class="t-footnote muted">
                CSV oder Excel (.xlsx), höchstens 5 MB und 5000 Zeilen. Die Kopfzeile wird gegen bekannte
                Spaltennamen abgeglichen (z. B. „Vorname", „first name", „Firstname").
            </p>

            <label class="dateifeld">
                <Icon name="import" :size="22" />
                <span v-if="datei">{{ datei.name }}</span>
                <span v-else class="muted">Datei auswählen …</span>
                <input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="dateiGewaehlt" />
            </label>

            <UiButton variant="primary" :disabled="!datei || laedt" @click="analysieren">
                {{ laedt ? 'Wird gelesen …' : 'Datei lesen und Zuordnung vorschlagen' }}
            </UiButton>
        </UiCard>

        <!-- Schritt 2: Zuordnung pruefen/korrigieren -->
        <UiCard v-if="schritt === 'zuordnung'" class="stack">
            <p class="t-headline">Spalten zuordnen</p>
            <p class="t-footnote muted">
                {{ totalRows }} Datenzeilen gefunden. Für jede Spalte wurde ein CRM-Feld vorgeschlagen — bitte prüfen
                und bei Bedarf korrigieren. Unbekannte Spalten sind auf „Ignorieren" gesetzt.
            </p>

            <div class="zuordnung-liste">
                <div v-for="(h, i) in kopf" :key="i" class="zuordnung-zeile">
                    <span class="spaltenname">{{ h || `Spalte ${i + 1}` }}</span>
                    <select v-model="mapping[i]" class="zuordnung-auswahl">
                        <option v-for="o in FELD_OPTIONEN" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>

            <UiBadge v-if="nachnameFehltIn" tone="warn">
                {{ nachnameFehltIn }} von {{ previewRows.length }} Vorschauzeilen ohne Nachname (Pflichtfeld) —
                diese Zeilen werden beim Übernehmen als fehlerhaft gemeldet.
            </UiBadge>

            <div class="knopfreihe">
                <UiButton @click="schritt = 'auswahl'">Zurück</UiButton>
                <UiButton :disabled="laedt" @click="uebernehmen(false)">
                    {{ laedt ? 'Wird übernommen …' : 'Direkt übernehmen' }}
                </UiButton>
                <UiButton variant="primary" :disabled="laedt" @click="zurVorschau">
                    {{ laedt ? 'Wird abgeglichen …' : 'Weiter zur Vorschau' }}
                </UiButton>
            </div>
        </UiCard>

        <!-- Schritt 3: Vorschau mit Abgleich gegen den Bestand -->
        <UiCard v-if="schritt === 'vorschau' && abgleich" class="stack">
            <p class="t-headline">Vorschau &amp; Abgleich</p>
            <p class="t-footnote muted">
                {{ abgleich.summary.totalRows }} Zeilen geprüft, {{ abgleich.summary.withMatches }} davon mit
                möglichem Treffer im Bestand. Beim Ergänzen werden nur LEERE Felder des Bestandskontakts gefüllt —
                vorhandene Werte bleiben unangetastet.
            </p>

            <div v-if="zeilenMitTreffer.length" class="knopfreihe knopfreihe--links">
                <UiButton size="sm" @click="alleSicherErgaenzen">Alle mit sicherem Treffer ergänzen</UiButton>
                <UiButton size="sm" @click="alleAlsNeuAnlegen">Alle als neu anlegen</UiButton>
            </div>

            <div class="abgleich-liste">
                <UiCard v-for="z in zeilenMitTreffer" :key="z.row" class="abgleich-zeile">
                    <p v-if="z.dateiDublette" class="t-footnote hinweis-datei">
                        Diese Person steht bereits in Zeile {{ z.dateiDublette }} dieser Datei.
                        Vorgeschlagen ist deshalb „Überspringen" — sonst entstehen zwei Kontakte
                        aus einem Import.
                    </p>
                    <div class="abgleich-kopf">
                        <span class="t-subhead abgleich-name">{{ z.name || '—' }}</span>
                        <span class="t-footnote muted">{{ z.email || 'keine E-Mail' }}</span>
                        <span v-if="z.firma" class="t-footnote muted">· {{ z.firma }}</span>
                    </div>

                    <UiSegmented :options="ENTSCHEIDUNG_OPTIONEN" :model-value="entscheidungFuer(z).action"
                                 @update:model-value="(a) => aktionSetzen(z, a)" />

                    <div v-if="entscheidungFuer(z).action === 'aktualisieren'" class="treffer-liste">
                        <label v-for="t in z.treffer" :key="t.id" class="treffer-kandidat">
                            <input v-if="z.treffer.length > 1" type="radio" :name="`kontakt-${z.row}`"
                                   :checked="entscheidungFuer(z).contactId === t.id" @change="kontaktWaehlen(z, t.id)" />
                            <div class="treffer-inhalt">
                                <div class="row">
                                    <UiBadge :tone="t.sicherheit === 'sicher' ? 'quiet' : 'warn'">
                                        {{ SICHERHEIT_LABEL[t.sicherheit] }}
                                    </UiBadge>
                                    <RouterLink :to="`/kontakte/${t.id}`" class="t-footnote" @click.stop>{{ t.name }}</RouterLink>
                                    <span class="t-footnote muted">{{ t.email || 'keine E-Mail' }}</span>
                                    <span v-if="t.firma" class="t-footnote muted">· {{ t.firma }}</span>
                                </div>
                                <p class="t-caption muted grund">{{ t.grund }}</p>
                            </div>
                        </label>
                    </div>
                </UiCard>
            </div>

            <p v-if="zeilenOhneTreffer.length" class="t-footnote muted">
                {{ zeilenOhneTreffer.length }} weitere Zeile{{ zeilenOhneTreffer.length === 1 ? '' : 'n' }} ohne
                Treffer im Bestand — {{ zeilenOhneTreffer.length === 1 ? 'wird' : 'werden' }} als neuer Kontakt angelegt.
            </p>

            <div class="knopfreihe">
                <UiButton @click="zurZuordnung">Zurück</UiButton>
                <UiButton variant="primary" :disabled="laedt" @click="uebernehmen(true)">
                    {{ laedt ? 'Wird übernommen …' : `${abgleich.summary.totalRows} Zeilen übernehmen` }}
                </UiButton>
            </div>
        </UiCard>

        <!-- Schritt 4: Bericht -->
        <UiCard v-if="schritt === 'bericht' && bericht" class="stack">
            <p class="t-headline">Ergebnis</p>

            <div class="kennzahlen">
                <div class="kennzahl">
                    <span class="t-large-title">{{ bericht.summary.imported }}</span>
                    <span class="t-footnote muted">übernommen</span>
                </div>
                <div class="kennzahl">
                    <span class="t-large-title">{{ bericht.summary.skipped }}</span>
                    <span class="t-footnote muted">übersprungen (Dublette)</span>
                </div>
                <div v-if="bericht.summary.updated != null" class="kennzahl">
                    <span class="t-large-title">{{ bericht.summary.updated }}</span>
                    <span class="t-footnote muted">ergänzt</span>
                </div>
                <div class="kennzahl">
                    <span class="t-large-title">{{ bericht.summary.failed }}</span>
                    <span class="t-footnote muted">fehlerhaft</span>
                </div>
            </div>

            <template v-if="bericht.updated?.length">
                <p class="t-caption">Ergänzte Bestandskontakte (nur zuvor leere Felder wurden gefüllt)</p>
                <ul class="berichtliste">
                    <li v-for="(u, i) in bericht.updated" :key="i">
                        Zeile {{ u.row }}:
                        <RouterLink :to="`/kontakte/${u.id}`">{{ u.name || '—' }}</RouterLink>
                        — ergänzt: {{ u.ergaenzteFelder.join(', ') || '—' }}
                    </li>
                </ul>
            </template>

            <template v-if="bericht.skippedDuplicates.length">
                <p class="t-caption">Übersprungene Dubletten (E-Mail bereits im Mandanten vorhanden)</p>
                <ul class="berichtliste">
                    <li v-for="(d, i) in bericht.skippedDuplicates" :key="i">
                        Zeile {{ d.row }}: {{ d.name || '—' }} ({{ d.email }})
                    </li>
                </ul>
            </template>

            <template v-if="bericht.errors.length">
                <p class="t-caption">Fehlerhafte Zeilen</p>
                <ul class="berichtliste">
                    <li v-for="(f, i) in bericht.errors" :key="i" class="fehlerzeile">
                        Zeile {{ f.row }}: {{ f.reason }}
                    </li>
                </ul>
            </template>

            <p class="t-footnote muted">
                Importierte Kontakte haben die Herkunft „Import" und noch KEINE Einwilligung — sie sind so lange
                nicht für Werbung freigegeben, bis eine eigenständige Einwilligung vorliegt.
            </p>

            <UiButton variant="primary" @click="neuerImport">Weitere Datei importieren</UiButton>
        </UiCard>
    </div>
</template>

<style scoped>
header p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }
.muted { color: var(--label-secondary); }

.schritte { display: flex; gap: var(--sp-4); flex-wrap: wrap; }
.schritt-punkt { display: flex; align-items: center; gap: var(--sp-2); color: var(--label-tertiary); }
.schritt-punkt.aktiv { color: var(--label-primary); }
.schritt-punkt.fertig { color: var(--label-secondary); }
.schritt-nr {
    display: grid; place-items: center;
    width: 22px; height: 22px;
    border-radius: var(--radius-pill);
    background: var(--fill-tertiary);
    font-size: var(--text-caption); font-weight: 700;
}
.schritt-punkt.aktiv .schritt-nr { background: var(--accent); color: #fff; }
.schritt-punkt.fertig .schritt-nr { background: var(--accent-quiet); color: var(--accent); }

.dateifeld {
    display: flex; align-items: center; gap: var(--sp-3);
    padding: var(--sp-5);
    border: 1px dashed var(--separator);
    border-radius: var(--radius-l);
    background: var(--bg-input);
    cursor: pointer;
    min-height: 44px;
}
.dateifeld input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }

.zuordnung-liste { display: flex; flex-direction: column; gap: var(--sp-2); }
.zuordnung-zeile {
    display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3);
    padding: var(--sp-3);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
}
.spaltenname { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.zuordnung-auswahl {
    font-family: inherit;
    font-size: var(--text-subhead);
    color: var(--label-primary);
    background: var(--bg-input);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    padding: 8px 12px;
    min-height: 40px;
    flex: none;
}

.knopfreihe { display: flex; gap: var(--sp-2); justify-content: flex-end; }
.knopfreihe :deep(.btn) { min-height: 44px; }
.knopfreihe--links { justify-content: flex-start; flex-wrap: wrap; }

.kennzahlen { display: flex; gap: var(--sp-6); flex-wrap: wrap; }
.kennzahl { display: flex; flex-direction: column; gap: 2px; }

.berichtliste { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--sp-1); }
.berichtliste li { font-size: var(--text-footnote); padding: var(--sp-2) var(--sp-3); border-radius: var(--radius-s); background: var(--fill-quaternary); }
.berichtliste li.fehlerzeile { color: var(--danger); }

/* Schritt 3: Abgleich gegen den Bestand */
.abgleich-liste { display: flex; flex-direction: column; gap: var(--sp-3); }
.abgleich-zeile { display: flex; flex-direction: column; gap: var(--sp-3); padding: var(--sp-4); }
.abgleich-kopf { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--sp-2); }
.abgleich-name { font-weight: 600; }

.treffer-liste { display: flex; flex-direction: column; gap: var(--sp-2); }
.hinweis-datei { color: var(--label-secondary); margin: 0 0 var(--sp-2); }
.treffer-kandidat {
    display: flex; align-items: flex-start; gap: var(--sp-3);
    padding: var(--sp-3);
    min-height: 44px;
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    background: var(--fill-quaternary);
    cursor: pointer;
}
.treffer-kandidat input[type="radio"] { margin-top: 3px; flex: none; width: 18px; height: 18px; }
.treffer-inhalt { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.treffer-inhalt .row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sp-2); }
.grund { margin: 0; }

@media (max-width: 700px) {
    .knopfreihe { flex-direction: column-reverse; }
    .knopfreihe :deep(.btn) { width: 100%; }
    .knopfreihe--links { flex-direction: row; }
    .knopfreihe--links :deep(.btn) { width: auto; }
    .zuordnung-zeile { flex-direction: column; align-items: stretch; }
    .zuordnung-auswahl { width: 100%; }
}
</style>
