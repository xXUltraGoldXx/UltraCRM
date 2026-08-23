<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const PHASEN = [
    { key: 'neu', label: 'Neu' },
    { key: 'qualifiziert', label: 'Qualifiziert' },
    { key: 'angebot', label: 'Angebot' },
    { key: 'verhandlung', label: 'Verhandlung' },
    { key: 'gewonnen', label: 'Gewonnen' },
    { key: 'verloren', label: 'Verloren' },
];

const deals = ref([]);
const laedt = ref(true);
const fehler = ref('');
const formOffen = ref(false);
const speichert = ref(false);
const formFehler = ref('');
const entwurf = ref({ title: '', value: '', stage: 'neu' });
const ziehtId = ref(null);
// Auf schmalen Bildschirmen wird eine Phase zur Zeit gezeigt; sechs Spalten
// nebeneinander sind auf dem Handy nicht bedienbar.
const mobilPhase = ref('neu');
const istSchmal = ref(window.matchMedia('(max-width: 820px)').matches);
window.matchMedia('(max-width: 820px)').addEventListener('change', (e) => { istSchmal.value = e.matches; });

const geld = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });

async function laden() {
    laedt.value = true;
    try {
        const { data } = await api.get('/deals');
        deals.value = data['hydra:member'] ?? data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Vorgänge konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

/* Export: Download über Blob, damit der Authorization-Header mitgeht — ein
   einfacher Link würde ohne Anmeldung landen (siehe PrivacyView.vue,
   auskunft()). Die Pipeline lädt serverseitig ohnehin alle Vorgänge, daher
   ohne zusätzliche Filterparameter. */
const exportOffen = ref(false);
const exportLaeuft = ref(false);

async function exportieren(format) {
    exportOffen.value = false;
    exportLaeuft.value = true;
    try {
        const antwort = await api.get(`/export/deals.${format}`, { responseType: 'blob' });
        const url = URL.createObjectURL(antwort.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `vorgaenge-${new Date().toISOString().slice(0, 10)}.${format}`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        fehler.value = 'Der Export konnte nicht erstellt werden.';
    } finally {
        exportLaeuft.value = false;
    }
}

const nachPhase = computed(() => Object.fromEntries(
    PHASEN.map((p) => [p.key, deals.value.filter((d) => d.stage === p.key)]),
));

function summe(phase) {
    const s = (nachPhase.value[phase] || []).reduce((acc, d) => acc + Number(d.value || 0), 0);
    return geld.format(s);
}

const offeneSumme = computed(() => geld.format(
    deals.value.filter((d) => d.open).reduce((a, d) => a + Number(d.value || 0), 0),
));

async function speichern() {
    speichert.value = true;
    formFehler.value = '';
    try {
        const nutzlast = { title: entwurf.value.title, stage: entwurf.value.stage };
        if (entwurf.value.value) nutzlast.value = String(entwurf.value.value);
        await api.post('/deals', nutzlast, { headers: { 'Content-Type': 'application/ld+json' } });
        formOffen.value = false;
        entwurf.value = { title: '', value: '', stage: 'neu' };
        await laden();
    } catch (e) {
        formFehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

/* Verschieben per Ziehen. Bei "verloren" ist eine Begruendung Pflicht —
   die API lehnt sonst ab, deshalb wird sie vorher erfragt. */
const verlustBlatt = ref(null); // { deal, phase }
const verlustGrund = ref('');
const verlustLaeuft = ref(false);

async function ablegen(phase) {
    const deal = deals.value.find((d) => d.id === ziehtId.value);
    ziehtId.value = null;
    if (deal) await verschiebe(deal, phase);
}

async function verschiebe(deal, phase) {
    if (!deal || deal.stage === phase) return;

    // Verloren braucht eine Begruendung — dafuer ein richtiges Blatt statt
    // eines Browser-Dialogs.
    if (phase === 'verloren') {
        verlustGrund.value = '';
        verlustBlatt.value = { deal, phase };
        return;
    }
    await schreibe(deal, { stage: phase }, phase);
}

async function verlustBestaetigen() {
    if (!verlustGrund.value.trim()) return;
    verlustLaeuft.value = true;
    const { deal } = verlustBlatt.value;
    await schreibe(deal, { stage: 'verloren', lostReason: verlustGrund.value.trim() }, 'verloren');
    verlustLaeuft.value = false;
    verlustBlatt.value = null;
}

async function schreibe(deal, patch, phase) {
    const vorher = deal.stage;
    deal.stage = phase; // sofortige Rueckmeldung
    try {
        await api.patch(`/deals/${deal.id}`, patch, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        await laden();
    } catch (e) {
        deal.stage = vorher; // zurueckrollen, wenn der Server ablehnt
        fehler.value = 'Verschieben hat nicht geklappt.';
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Pipeline</h2>
                <p class="t-subhead">{{ offeneSumme }} in offenen Vorgängen</p>
            </div>
            <div class="row head__aktionen">
                <div class="export">
                    <UiButton variant="quiet" size="sm" :disabled="exportLaeuft" @click="exportOffen = !exportOffen">
                        {{ exportLaeuft ? 'Wird erstellt…' : 'Exportieren' }}
                    </UiButton>
                    <div v-if="exportOffen" class="export__menu">
                        <button type="button" @click="exportieren('csv')">Als CSV</button>
                        <button type="button" @click="exportieren('xlsx')">Als Excel</button>
                    </div>
                </div>
                <UiButton variant="primary" @click="formOffen = !formOffen">
                    <Icon name="plus" :size="16" /> Vorgang anlegen
                </UiButton>
            </div>
        </header>

        <UiCard v-if="formOffen" class="form">
            <div class="form__grid">
                <UiField v-model="entwurf.title" label="Titel" placeholder="z. B. Wartungsvertrag" />
                <UiField v-model="entwurf.value" label="Wert in Euro" type="number" placeholder="0.00" />
                <label class="field">
                    <span class="field__label">Phase</span>
                    <select v-model="entwurf.stage">
                        <option v-for="p in PHASEN.slice(0, 4)" :key="p.key" :value="p.key">{{ p.label }}</option>
                    </select>
                </label>
            </div>
            <p v-if="formFehler" class="t-footnote fehler">{{ formFehler }}</p>
            <div class="row form__actions">
                <UiButton variant="quiet" @click="formOffen = false">Abbrechen</UiButton>
                <UiButton variant="primary" :disabled="speichert || !entwurf.title" @click="speichern">Speichern</UiButton>
            </div>
        </UiCard>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiSegmented v-if="istSchmal" v-model="mobilPhase" class="phasenwahl"
                     :options="PHASEN.map((p) => ({ value: p.key, label: p.label }))" />

        <div class="board" :class="{ 'board--einzeln': istSchmal }">
            <section v-for="p in PHASEN.filter((x) => !istSchmal || x.key === mobilPhase)" :key="p.key" class="spalte"
                     @dragover.prevent @drop="ablegen(p.key)">
                <header class="spalte__kopf">
                    <span class="t-caption">{{ p.label }}</span>
                    <span class="t-footnote">{{ (nachPhase[p.key] || []).length }} · {{ summe(p.key) }}</span>
                </header>

                <article v-for="d in nachPhase[p.key]" :key="d.id" class="deal"
                         draggable="true" @dragstart="ziehtId = d.id">
                    <p class="deal__titel">{{ d.title }}</p>
                    <p class="t-footnote">{{ d.value ? geld.format(Number(d.value)) : 'ohne Wert' }}</p>
                    <p v-if="d.company" class="t-footnote muted">{{ d.company.name }}</p>

                    <!-- Ziehen geht auf dem Handy nicht zuverlaessig: dort
                         wechselt die Phase ueber eine Auswahl direkt auf der Karte. -->
                    <select v-if="istSchmal" class="phasewechsel" :value="d.stage"
                            @change="verschiebe(d, $event.target.value)">
                        <option v-for="p2 in PHASEN" :key="p2.key" :value="p2.key">→ {{ p2.label }}</option>
                    </select>
                </article>

                <p v-if="!(nachPhase[p.key] || []).length" class="leer t-footnote">Nichts hier</p>
            </section>
        </div>

        <UiSheet :offen="!!verlustBlatt" titel="Vorgang verloren"
                 :text="`Warum ging „${verlustBlatt?.deal?.title ?? ''}“ verloren? Die Begründung hilft später beim Auswerten.`"
                 bestaetigen="Als verloren markieren" ton="danger" :laeuft="verlustLaeuft"
                 @schliessen="verlustBlatt = null" @bestaetigen="verlustBestaetigen">
            <UiField v-model="verlustGrund" label="Grund" placeholder="z. B. Preis, Zeitpunkt, Mitbewerber" />
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.head__aktionen { flex-wrap: wrap; }
.fehler { color: var(--danger); }

/* Export: unauffälliger Knopf mit kleinem Auswahlmenü CSV/Excel. */
.export { position: relative; }
.export__menu {
    position: absolute; top: calc(100% + var(--sp-2)); right: 0; z-index: 10;
    display: flex; flex-direction: column; min-width: 140px;
    background: var(--bg-elevated); border: 1px solid var(--separator);
    border-radius: var(--radius-m); box-shadow: var(--shadow-card);
    overflow: hidden;
}
.export__menu button {
    appearance: none; border: 0; background: transparent; text-align: left;
    font-family: inherit; font-size: var(--text-subhead); color: var(--label-primary);
    padding: var(--sp-3) var(--sp-4); cursor: pointer; min-height: 44px;
}
.export__menu button:hover { background: var(--fill-quaternary); }

.form { display: flex; flex-direction: column; gap: var(--sp-4); }
.form__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-4); }
.form__actions { justify-content: flex-end; }
.field { display: flex; flex-direction: column; gap: var(--sp-2); }
.field__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }
select {
    font-family: inherit; font-size: var(--text-body); color: var(--label-primary);
    background: var(--bg-input); border: 1px solid var(--separator);
    border-radius: var(--radius-m); padding: 11px 14px;
}

/* Die Klasse landet auf dem Wurzelelement der Komponente — das IST bereits
   das Segment-Element. Ein :deep()-Nachfahrenselektor liefe hier ins Leere. */
.phasenwahl {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2px;
}
.phasenwahl :deep(button) { min-height: 40px; }
.board--einzeln { grid-template-columns: 1fr !important; }
.phasewechsel {
    margin-top: var(--sp-3); width: 100%; min-height: 40px;
    font-family: inherit; font-size: var(--text-footnote);
    color: var(--accent); background: var(--accent-quiet);
    border: 0; border-radius: var(--radius-s); padding: 0 var(--sp-2);
}
.board { display: grid; grid-template-columns: repeat(6, minmax(168px, 1fr)); gap: var(--sp-3); overflow-x: auto; padding-bottom: var(--sp-2); }
.spalte {
    display: flex; flex-direction: column; gap: var(--sp-2);
    background: var(--bg-base);
    border: 1px solid var(--separator);
    border-radius: var(--radius-l);
    padding: var(--sp-3);
    min-height: 240px;
}
.spalte__kopf { display: flex; flex-direction: column; gap: 2px; padding: var(--sp-1) var(--sp-2) var(--sp-2); }
.spalte__kopf .t-caption { margin: 0; }

.deal {
    background: var(--bg-elevated);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    padding: var(--sp-3);
    cursor: grab;
    transition: border-color .16s ease, transform .16s ease;
}
.deal:active { cursor: grabbing; transform: scale(.99); }
.deal:hover { border-color: var(--accent); }
.deal p { margin: 0; }
.deal__titel { font-size: var(--text-subhead); font-weight: 600; margin-bottom: 2px; }

.leer { text-align: center; color: var(--label-tertiary); padding: var(--sp-4) 0; }

@media (max-width: 1100px) { .board { grid-template-columns: repeat(6, 200px); } }
</style>
