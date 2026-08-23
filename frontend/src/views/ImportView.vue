<script setup>
import { computed, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';

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

const vorschauEmailAnzahl = computed(() => {
    const m = {};
    for (const z of previewRows.value) {
        const e = spalteWert(z, 'email').toLowerCase();
        if (e) m[e] = (m[e] || 0) + 1;
    }
    return m;
});
function istVorschauDuplikat(zeile) {
    const e = spalteWert(zeile, 'email').toLowerCase();
    return !!e && vorschauEmailAnzahl.value[e] > 1;
}

function zurZuordnung() {
    schritt.value = 'zuordnung';
}
function zurVorschau() {
    schritt.value = 'vorschau';
}

async function uebernehmen() {
    if (!datei.value) return;
    laedt.value = true;
    fehler.value = '';
    try {
        const form = new FormData();
        form.append('file', datei.value);
        form.append('mapping', JSON.stringify(mapping.value));
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

            <div class="knopfreihe">
                <UiButton @click="schritt = 'auswahl'">Zurück</UiButton>
                <UiButton variant="primary" @click="zurVorschau">Weiter zur Vorschau</UiButton>
            </div>
        </UiCard>

        <!-- Schritt 3: Vorschau -->
        <UiCard v-if="schritt === 'vorschau'" class="stack">
            <p class="t-headline">Vorschau</p>
            <p class="t-footnote muted">
                Die ersten {{ previewRows.length }} von {{ totalRows }} Zeilen mit der gewählten Zuordnung.
                Dubletten gegen bereits vorhandene Kontakte werden erst beim Übernehmen geprüft und übersprungen —
                nicht überschrieben.
            </p>

            <UiBadge v-if="nachnameFehltIn" tone="warn">
                {{ nachnameFehltIn }} von {{ previewRows.length }} Vorschauzeilen ohne Nachname (Pflichtfeld) —
                diese Zeilen werden beim Übernehmen als fehlerhaft gemeldet.
            </UiBadge>

            <div class="tabelle-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Vorname</th><th>Nachname</th><th>E-Mail</th><th>Telefon</th>
                            <th>Firma</th><th>Position</th><th>Abteilung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(z, i) in previewRows" :key="i" :class="{ warnung: spalteWert(z, 'lastName') === '' }">
                            <td>{{ spalteWert(z, 'firstName') || '—' }}</td>
                            <td>
                                <span v-if="spalteWert(z, 'lastName')">{{ spalteWert(z, 'lastName') }}</span>
                                <UiBadge v-else tone="warn">fehlt</UiBadge>
                            </td>
                            <td>
                                {{ spalteWert(z, 'email') || '—' }}
                                <UiBadge v-if="istVorschauDuplikat(z)" tone="warn">Dublette in Datei</UiBadge>
                            </td>
                            <td>{{ spalteWert(z, 'phone') || '—' }}</td>
                            <td>{{ spalteWert(z, 'company') || '—' }}</td>
                            <td>{{ spalteWert(z, 'position') || '—' }}</td>
                            <td>{{ spalteWert(z, 'department') || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="knopfreihe">
                <UiButton @click="zurZuordnung">Zurück</UiButton>
                <UiButton variant="primary" :disabled="laedt" @click="uebernehmen">
                    {{ laedt ? 'Wird übernommen …' : `${totalRows} Zeilen übernehmen` }}
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
                <div class="kennzahl">
                    <span class="t-large-title">{{ bericht.summary.failed }}</span>
                    <span class="t-footnote muted">fehlerhaft</span>
                </div>
            </div>

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

.tabelle-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 640px; }
th { text-align: left; padding: var(--sp-2) var(--sp-3); font-size: var(--text-caption); color: var(--label-tertiary); }
td { padding: var(--sp-2) var(--sp-3); border-top: 1px solid var(--separator); font-size: var(--text-subhead); white-space: nowrap; }
tr.warnung td { background: color-mix(in srgb, var(--warning) 8%, transparent); }

.kennzahlen { display: flex; gap: var(--sp-6); flex-wrap: wrap; }
.kennzahl { display: flex; flex-direction: column; gap: 2px; }

.berichtliste { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--sp-1); }
.berichtliste li { font-size: var(--text-footnote); padding: var(--sp-2) var(--sp-3); border-radius: var(--radius-s); background: var(--fill-quaternary); }
.berichtliste li.fehlerzeile { color: var(--danger); }

@media (max-width: 700px) {
    .knopfreihe { flex-direction: column-reverse; }
    .knopfreihe :deep(.btn) { width: 100%; }
    .zuordnung-zeile { flex-direction: column; align-items: stretch; }
    .zuordnung-auswahl { width: 100%; }
}
</style>
