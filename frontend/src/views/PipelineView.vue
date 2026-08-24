<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';
import UiSheet from '../components/ui/UiSheet.vue';
import AenderungsProtokoll from '../components/AenderungsProtokoll.vue';
import { geld } from '../format.js';

// Phase eines Vorgangs als IRI fuer POST/PATCH — die API erwartet
// {"stage": "/api/stages/<id>"}.
function iri(phase) {
    return `/api/stages/${phase.id}`;
}

const auth = useAuthStore();
const deals = ref([]);
const zusatzfelder = ref([]);
const pipelines = ref([]);
const pipelineId = ref(null);
const phasen = ref([]);
const laedt = ref(true);
const fehler = ref('');
const formOffen = ref(false);
const speichert = ref(false);
const formFehler = ref('');
const entwurf = ref({ title: '', value: '', stage: '' });
const ziehtId = ref(null);
// Vorgang-Details im Blatt: Kontakt und Firma haengen am Vorgang nur als
// IRI (anders als "stage"), muessen fuer die Anzeige also extra geladen
// werden.
const gewaehlterVorgang = ref(null);
const vorgangFirma = ref(null);
const vorgangKontakt = ref(null);
// Auf schmalen Bildschirmen wird eine Phase zur Zeit gezeigt; mehrere Spalten
// nebeneinander sind auf dem Handy nicht bedienbar.
const mobilPhase = ref(null);
const istSchmal = ref(window.matchMedia('(max-width: 820px)').matches);
window.matchMedia('(max-width: 820px)').addEventListener('change', (e) => { istSchmal.value = e.matches; });

async function laden() {
    laedt.value = true;
    try {
        const [p, d, z] = await Promise.all([
            api.get('/pipelines'),
            api.get('/deals'),
            api.get('/custom_field_definitions', { params: { entityType: 'deal' } }),
        ]);
        pipelines.value = (p.data['hydra:member'] ?? p.data.member ?? [])
            .slice()
            .sort((a, b) => a.position - b.position);
        deals.value = d.data['hydra:member'] ?? d.data.member ?? [];
        zusatzfelder.value = (z.data['hydra:member'] ?? z.data.member ?? [])
            .filter((x) => x.entityType === 'deal');
        // Auswahl beibehalten, wenn die Pipeline noch existiert — sonst die
        // mit der niedrigsten position vorauswaehlen.
        if (!pipelines.value.some((pl) => pl.id === pipelineId.value)) {
            pipelineId.value = pipelines.value[0]?.id ?? null;
        }
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Vorgänge konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

async function ladePhasen() {
    if (!pipelineId.value) { phasen.value = []; return; }
    try {
        const { data } = await api.get('/stages', { params: { pipeline: `/api/pipelines/${pipelineId.value}` } });
        phasen.value = (data['hydra:member'] ?? data.member ?? []).slice().sort((a, b) => a.position - b.position);
    } catch (e) {
        fehler.value = 'Die Phasen konnten nicht geladen werden.';
    }
}
watch(pipelineId, ladePhasen);

// Anlage-Formular: nur offene Phasen anbieten. Verloren-Phasen verlangen
// serverseitig einen Verlustgrund, fuer den es im Anlage-Formular kein Feld
// gibt — waehlbar waeren sie eine Sackgasse ohne erkennbaren Grund.
const offenePhasen = computed(() => phasen.value.filter((p) => p.art === 'offen'));

// Vorbelegung fuer neue Vorgaenge (erste offene Phase) und die Handy-
// Spaltenauswahl (erste Phase ueberhaupt, nach position) der gerade
// gewaehlten Pipeline setzen.
watch(phasen, (neu) => {
    if (!neu.length) return;
    const ersteOffene = offenePhasen.value[0];
    if (ersteOffene) entwurf.value.stage = iri(ersteOffene);
    if (!neu.some((p) => p.id === mobilPhase.value)) {
        mobilPhase.value = neu[0].id;
    }
});

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
    phasen.value.map((p) => [p.id, deals.value.filter((d) => d.stage?.id === p.id)]),
));

function summe(phaseId) {
    const s = (nachPhase.value[phaseId] || []).reduce((acc, d) => acc + Number(d.value || 0), 0);
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
        entwurf.value = { title: '', value: '', stage: offenePhasen.value[0] ? iri(offenePhasen.value[0]) : '' };
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
// Zaehler, der bei jedem Schliessen des Blatts hochgezaehlt wird. Er fliesst
// in den :key des Handy-Auswahlfelds ein und erzwingt so dessen Neuaufbau —
// sonst bliebe das <select> nach einem Abbruch optisch auf der eben
// gewaehlten Verloren-Phase stehen, obwohl d.stage.id sich nie geaendert hat.
const mobilAuswahlTick = ref(0);

function verlustAbbrechen() {
    verlustBlatt.value = null;
    mobilAuswahlTick.value += 1;
}

async function ablegen(phase) {
    const deal = deals.value.find((d) => d.id === ziehtId.value);
    ziehtId.value = null;
    if (deal) await verschiebe(deal, phase);
}

async function verschiebe(deal, phase) {
    if (!deal || !phase || deal.stage?.id === phase.id) return;

    // Verloren braucht eine Begruendung — dafuer ein richtiges Blatt statt
    // eines Browser-Dialogs. Nicht der Name, sondern die "art" der Phase
    // entscheidet, ob es sich um den Verloren-Abschluss handelt.
    if (phase.art === 'verloren') {
        verlustGrund.value = '';
        verlustBlatt.value = { deal, phase };
        return;
    }
    await schreibe(deal, { stage: iri(phase) }, phase);
}

async function verlustBestaetigen() {
    if (!verlustGrund.value.trim()) return;
    verlustLaeuft.value = true;
    const { deal, phase } = verlustBlatt.value;
    await schreibe(deal, { stage: iri(phase), lostReason: verlustGrund.value.trim() }, phase);
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

/* --- Vorgang-Details im Blatt ----------------------------------------
   Ein Klick auf eine Karte oeffnet das Blatt; ein Ziehen (draggable/
   dragstart) verschiebt sie weiterhin — beides steht nebeneinander auf
   demselben Element, ohne sich in die Quere zu kommen: der Browser feuert
   nach einem tatsaechlichen Drag kein click-Event, click und dragstart
   schliessen sich also gegenseitig aus. Nur das Auswahlfeld fuer den
   Phasenwechsel auf dem Handy stoppt seinen Klick separat, damit es sich
   oeffnen laesst, ohne gleich das Blatt mit aufzureissen. */
function idAusIri(wert) {
    if (!wert) return null;
    return typeof wert === 'string' ? wert.split('/').pop() : wert.id;
}

async function vorgangOeffnen(deal) {
    gewaehlterVorgang.value = deal;
    vorgangFirma.value = null;
    vorgangKontakt.value = null;
    // Einzeln fangen statt Promise.all: fehlt nur eines der beiden Rechte,
    // soll das andere trotzdem angezeigt werden.
    const [f, k] = await Promise.all([
        deal.company ? api.get(`/companies/${idAusIri(deal.company)}`).catch(() => null) : null,
        deal.contact ? api.get(`/contacts/${idAusIri(deal.contact)}`).catch(() => null) : null,
    ]);
    vorgangFirma.value = f?.data ?? null;
    vorgangKontakt.value = k?.data ?? null;
}

function vorgangSchliessen() {
    gewaehlterVorgang.value = null;
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
                <div v-if="auth.darf('importexport.use')" class="export">
                    <UiButton variant="quiet" size="sm" :disabled="exportLaeuft" @click="exportOffen = !exportOffen">
                        {{ exportLaeuft ? 'Wird erstellt…' : 'Exportieren' }}
                    </UiButton>
                    <div v-if="exportOffen" class="export__menu">
                        <button type="button" @click="exportieren('csv')">Als CSV</button>
                        <button type="button" @click="exportieren('xlsx')">Als Excel</button>
                    </div>
                </div>
                <UiButton variant="primary" v-if="auth.darf('deals.manage')" @click="formOffen = !formOffen">
                    <Icon name="plus" :size="16" /> Vorgang anlegen
                </UiButton>
            </div>
        </header>

        <label v-if="pipelines.length > 1" class="field pipeline-wahl">
            <span class="field__label">Pipeline</span>
            <select v-model="pipelineId">
                <option v-for="pl in pipelines" :key="pl.id" :value="pl.id">{{ pl.name }}</option>
            </select>
        </label>

        <UiCard v-if="formOffen" class="form">
            <div class="form__grid">
                <UiField v-model="entwurf.title" label="Titel" placeholder="z. B. Wartungsvertrag" />
                <UiField v-model="entwurf.value" label="Wert in Euro" type="number" placeholder="0.00" />
                <label class="field">
                    <span class="field__label">Phase</span>
                    <select v-model="entwurf.stage">
                        <option v-for="p in offenePhasen" :key="p.id" :value="iri(p)">{{ p.name }}</option>
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
                     :options="phasen.map((p) => ({ value: p.id, label: p.name }))" />

        <div class="board" :class="{ 'board--einzeln': istSchmal }">
            <section v-for="p in phasen.filter((x) => !istSchmal || x.id === mobilPhase)" :key="p.id" class="spalte"
                     @dragover.prevent @drop="ablegen(p)">
                <header class="spalte__kopf">
                    <span class="t-caption">{{ p.name }}</span>
                    <span class="t-footnote">{{ (nachPhase[p.id] || []).length }} · {{ summe(p.id) }}</span>
                </header>

                <article v-for="d in nachPhase[p.id]" :key="d.id" class="deal"
                         draggable="true" @dragstart="ziehtId = d.id" @click="vorgangOeffnen(d)">
                    <p class="deal__titel">{{ d.title }}</p>
                    <p class="t-footnote">{{ d.value ? geld.format(Number(d.value)) : 'ohne Wert' }}</p>
                    <p v-if="d.company" class="t-footnote muted">{{ d.company.name }}</p>

                    <!-- Zusatzfelder nur zeigen, wenn befuellt — sonst
                         verstopfen leere Zeilen die Karte. -->
                    <p v-for="f in zusatzfelder.filter((x) => d.customData?.[x.fieldKey] !== undefined && d.customData?.[x.fieldKey] !== null && d.customData?.[x.fieldKey] !== '')"
                       :key="f.id" class="t-footnote zusatz">
                        {{ f.label }}:
                        <template v-if="f.type === 'janein'">{{ d.customData[f.fieldKey] ? 'ja' : 'nein' }}</template>
                        <template v-else>{{ d.customData[f.fieldKey] }}</template>
                    </p>

                    <!-- Ziehen geht auf dem Handy nicht zuverlaessig: dort
                         wechselt die Phase ueber eine Auswahl direkt auf der Karte. -->
                    <select v-if="istSchmal" :key="`${d.id}-${mobilAuswahlTick}`" class="phasewechsel" :value="d.stage?.id"
                            @click.stop
                            @change="verschiebe(d, phasen.find((p2) => String(p2.id) === $event.target.value))">
                        <option v-for="p2 in phasen" :key="p2.id" :value="p2.id">→ {{ p2.name }}</option>
                    </select>
                </article>

                <p v-if="!(nachPhase[p.id] || []).length" class="leer t-footnote">Nichts hier</p>
            </section>
        </div>

        <UiSheet :offen="!!verlustBlatt" titel="Vorgang verloren"
                 :text="`Warum ging „${verlustBlatt?.deal?.title ?? ''}“ verloren? Die Begründung hilft später beim Auswerten.`"
                 bestaetigen="Als verloren markieren" ton="danger" :laeuft="verlustLaeuft"
                 @schliessen="verlustAbbrechen" @bestaetigen="verlustBestaetigen">
            <UiField v-model="verlustGrund" label="Grund" placeholder="z. B. Preis, Zeitpunkt, Mitbewerber" />
        </UiSheet>

        <UiSheet :offen="!!gewaehlterVorgang" :titel="gewaehlterVorgang?.title" ohneAktionen
                 @schliessen="vorgangSchliessen">
            <template v-if="gewaehlterVorgang">
                <dl class="vorgang-details">
                    <dt class="t-footnote">Wert</dt>
                    <dd>{{ gewaehlterVorgang.value ? geld.format(Number(gewaehlterVorgang.value)) : 'ohne Wert' }}</dd>
                    <dt class="t-footnote">Phase</dt>
                    <dd>{{ gewaehlterVorgang.stageName }}</dd>
                    <template v-if="vorgangFirma">
                        <dt class="t-footnote">Firma</dt>
                        <dd>{{ vorgangFirma.name }}</dd>
                    </template>
                    <template v-if="vorgangKontakt">
                        <dt class="t-footnote">Kontakt</dt>
                        <dd>{{ vorgangKontakt.displayName }}</dd>
                    </template>
                </dl>

                <AenderungsProtokoll subjectType="deal" :subjectId="gewaehlterVorgang.id" />
            </template>
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

/* Nur sichtbar, wenn der Mandant mehr als eine Pipeline hat. */
.pipeline-wahl { max-width: 280px; }
.pipeline-wahl select { min-height: 44px; }

/* Die Klasse landet auf dem Wurzelelement der Komponente — das IST bereits
   das Segment-Element. Ein :deep()-Nachfahrenselektor liefe hier ins Leere.
   auto-fit statt einer festen Spaltenzahl, weil Pipelines unterschiedlich
   viele Phasen haben koennen. */
.phasenwahl {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
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
.board { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: var(--sp-3); overflow-x: auto; padding-bottom: var(--sp-2); }
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
.zusatz { color: var(--label-tertiary); margin-top: 2px; }

.leer { text-align: center; color: var(--label-tertiary); padding: var(--sp-4) 0; }

.vorgang-details { margin: 0; display: grid; gap: var(--sp-1); }
.vorgang-details dt { color: var(--label-tertiary); }
.vorgang-details dd { margin: 0 0 var(--sp-2); font-size: var(--text-subhead); }

@media (max-width: 1100px) { .board { grid-template-columns: repeat(auto-fill, 200px); } }
</style>
