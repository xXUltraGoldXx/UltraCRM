<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { STAGE_ART, STAGE_ART_HINWEIS } from '../labels.js';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';

const auth = useAuthStore();
const darfVerwalten = () => auth.darf('pipelines.manage');

const ART_TON = { offen: 'quiet', gewonnen: 'positive', verloren: 'warn' };
const ART_OPTIONEN = Object.entries(STAGE_ART).map(([value, label]) => ({ value, label }));

const pipelines = ref([]);
const laedt = ref(true);
const fehler = ref('');

async function laden() {
    laedt.value = true;
    try {
        const { data } = await api.get('/pipelines');
        pipelines.value = data['hydra:member'] ?? data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = e?.response?.status === 403
            ? 'Dieser Bereich ist ohne das Recht „Vorgänge ansehen“ nicht einsehbar.'
            : 'Die Pipelines konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

function nachricht(e, standard) {
    return e?.response?.data?.detail
        || e?.response?.data?.['hydra:description']
        || standard;
}

/* ---------------------------------------------------------------- Pipeline */

const pipelineBlattOffen = ref(false);
const pipelineBearbeiteId = ref(null);
const pipelineEntwurf = ref({ name: '' });
const pipelineSpeichert = ref(false);
const pipelineFehler = ref('');

function pipelineNeu() {
    pipelineBearbeiteId.value = null;
    pipelineEntwurf.value = { name: '' };
    pipelineFehler.value = '';
    pipelineBlattOffen.value = true;
}
function pipelineUmbenennen(p) {
    pipelineBearbeiteId.value = p.id;
    pipelineEntwurf.value = { name: p.name };
    pipelineFehler.value = '';
    pipelineBlattOffen.value = true;
}
async function pipelineSpeichern() {
    pipelineSpeichert.value = true;
    pipelineFehler.value = '';
    try {
        if (pipelineBearbeiteId.value) {
            await api.patch(`/pipelines/${pipelineBearbeiteId.value}`, { name: pipelineEntwurf.value.name }, {
                headers: { 'Content-Type': 'application/merge-patch+json' },
            });
        } else {
            await api.post('/pipelines', {
                name: pipelineEntwurf.value.name,
                position: pipelines.value.length + 1,
            }, { headers: { 'Content-Type': 'application/ld+json' } });
        }
        pipelineBlattOffen.value = false;
        await laden();
    } catch (e) {
        pipelineFehler.value = nachricht(e, 'Speichern hat nicht geklappt.');
    } finally {
        pipelineSpeichert.value = false;
    }
}

// Bestaetigungsblatt vor dem Loeschen — unumkehrbarer Schritt, siehe
// Dubletten-Zusammenfuehrung fuer dasselbe Muster.
const pipelineZumLoeschen = ref(null);
const pipelineLoescht = ref(false);
const pipelineLoeschenFehler = ref('');

function pipelineLoeschenFragen(p) {
    pipelineZumLoeschen.value = p;
    pipelineLoeschenFehler.value = '';
}
async function pipelineLoeschenBestaetigen() {
    pipelineLoescht.value = true;
    pipelineLoeschenFehler.value = '';
    try {
        await api.delete(`/pipelines/${pipelineZumLoeschen.value.id}`);
        pipelineZumLoeschen.value = null;
        await laden();
    } catch (e) {
        // Haengen noch Vorgaenge in einer Phase dieser Pipeline, antwortet die
        // API mit 409 und einer verstaendlichen Meldung im Feld "detail" —
        // die zeigen wir hier direkt im Blatt, statt es kommentarlos zu schliessen.
        pipelineLoeschenFehler.value = nachricht(e, 'Löschen hat nicht geklappt.');
    } finally {
        pipelineLoescht.value = false;
    }
}

/* -------------------------------------------------------------------- Phase */

const phaseBlattOffen = ref(false);
const phasePipelineId = ref(null);
const phaseBearbeiteId = ref(null);
const phaseEntwurf = ref({ name: '', art: 'offen' });
const phaseSpeichert = ref(false);
const phaseFehler = ref('');

function phaseNeu(pipeline) {
    phasePipelineId.value = pipeline.id;
    phaseBearbeiteId.value = null;
    phaseEntwurf.value = { name: '', art: 'offen' };
    phaseFehler.value = '';
    phaseBlattOffen.value = true;
}
function phaseBearbeiten(pipeline, phase) {
    phasePipelineId.value = pipeline.id;
    phaseBearbeiteId.value = phase.id;
    phaseEntwurf.value = { name: phase.name, art: phase.art };
    phaseFehler.value = '';
    phaseBlattOffen.value = true;
}
async function phaseSpeichern() {
    phaseSpeichert.value = true;
    phaseFehler.value = '';
    try {
        if (phaseBearbeiteId.value) {
            await api.patch(`/stages/${phaseBearbeiteId.value}`, {
                name: phaseEntwurf.value.name,
                art: phaseEntwurf.value.art,
            }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        } else {
            const pipeline = pipelines.value.find((p) => p.id === phasePipelineId.value);
            await api.post('/stages', {
                name: phaseEntwurf.value.name,
                art: phaseEntwurf.value.art,
                pipeline: `/api/pipelines/${phasePipelineId.value}`,
                position: (pipeline?.stages.length ?? 0) + 1,
            }, { headers: { 'Content-Type': 'application/ld+json' } });
        }
        phaseBlattOffen.value = false;
        await laden();
    } catch (e) {
        phaseFehler.value = nachricht(e, 'Speichern hat nicht geklappt.');
    } finally {
        phaseSpeichert.value = false;
    }
}

const phaseZumLoeschen = ref(null); // { pipeline, phase }
const phaseLoescht = ref(false);
const phaseLoeschenFehler = ref('');

function phaseLoeschenFragen(pipeline, phase) {
    phaseZumLoeschen.value = { pipeline, phase };
    phaseLoeschenFehler.value = '';
}
async function phaseLoeschenBestaetigen() {
    phaseLoescht.value = true;
    phaseLoeschenFehler.value = '';
    try {
        await api.delete(`/stages/${phaseZumLoeschen.value.phase.id}`);
        phaseZumLoeschen.value = null;
        await laden();
    } catch (e) {
        phaseLoeschenFehler.value = nachricht(e, 'Löschen hat nicht geklappt.');
    } finally {
        phaseLoescht.value = false;
    }
}

// Reihenfolge: Position mit dem Nachbarn tauschen. Kein Drag&Drop — auf dem
// Handy ist hoch/runter zuverlaessiger zu treffen.
const verschiebtPhaseId = ref(null);
async function phaseVerschieben(pipeline, phase, richtung) {
    const liste = pipeline.stages;
    const idx = liste.findIndex((s) => s.id === phase.id);
    const zielIdx = idx + richtung;
    if (zielIdx < 0 || zielIdx >= liste.length) return;
    const nachbar = liste[zielIdx];

    verschiebtPhaseId.value = phase.id;
    fehler.value = '';
    try {
        // Nacheinander statt parallel: schlaegt der zweite PATCH fehl, nachdem
        // der erste bereits durch ist, ist der Fehlerfall eindeutig zuzuordnen.
        // In jedem Fall — egal ob der erste oder der zweite Aufruf scheitert —
        // wird unten neu geladen, damit die Anzeige nie vom tatsaechlichen
        // Serverstand abweicht (siehe Befund: zwei Phasen auf derselben Position).
        await api.patch(`/stages/${phase.id}`, { position: nachbar.position }, {
            headers: { 'Content-Type': 'application/merge-patch+json' },
        });
        await api.patch(`/stages/${nachbar.id}`, { position: phase.position }, {
            headers: { 'Content-Type': 'application/merge-patch+json' },
        });
        await laden();
    } catch (e) {
        // laden() setzt fehler.value bei Erfolg selbst zurueck — deshalb erst
        // neu laden und die Meldung danach setzen, sonst waere sie sofort wieder weg.
        await laden();
        fehler.value = nachricht(e, 'Die Reihenfolge konnte nicht vollständig geändert werden. Die Ansicht wurde neu geladen.');
    } finally {
        verschiebtPhaseId.value = null;
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Pipelines</h2>
                <p class="t-subhead">Vertriebsprozesse mit ihren Phasen — je Mandant frei einstellbar.</p>
            </div>
            <UiButton v-if="darfVerwalten()" variant="primary" @click="pipelineNeu">
                <Icon name="plus" :size="16" /> Pipeline anlegen
            </UiButton>
        </header>

        <UiCard class="hinweis">
            <p class="t-footnote muted">
                Die <strong>Art</strong> einer Phase (Offen, Gewonnen oder Verloren) entscheidet, ob ein Vorgang darin
                in der Auswertung als offen, gewonnen oder verloren zählt — unabhängig davon, wie die Phase heißt.
                Eine Phase mit dem Namen „Abgeschlossen“ zählt also nur dann als gewonnen, wenn ihre Art auf
                <strong>Gewonnen</strong> steht.
            </p>
        </UiCard>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!laedt && !pipelines.length" class="leer">
            <p class="t-headline">Noch keine Pipeline</p>
            <p class="t-subhead">Lege eine Pipeline an, um Vorgänge in eigenen Phasen zu führen.</p>
        </UiCard>

        <UiCard v-for="p in pipelines" :key="p.id" class="pipeline">
            <div class="pipeline__kopf">
                <p class="t-headline">{{ p.name }}</p>
                <div class="spacer" />
                <template v-if="darfVerwalten()">
                    <UiButton size="sm" @click="pipelineUmbenennen(p)">Umbenennen</UiButton>
                    <UiButton size="sm" variant="danger" @click="pipelineLoeschenFragen(p)">Löschen</UiButton>
                </template>
            </div>

            <div v-if="!p.stages.length" class="leer leer--phase">
                <p class="t-subhead">Noch keine Phase in dieser Pipeline.</p>
            </div>

            <div v-for="(s, i) in p.stages" :key="s.id" class="phase">
                <div class="phase__pfeile">
                    <button type="button" class="pfeil" title="Nach oben"
                            :disabled="i === 0 || verschiebtPhaseId === s.id || !darfVerwalten()"
                            @click="phaseVerschieben(p, s, -1)">
                        <Icon name="chevron" :size="14" class="icon-hoch" />
                    </button>
                    <button type="button" class="pfeil" title="Nach unten"
                            :disabled="i === p.stages.length - 1 || verschiebtPhaseId === s.id || !darfVerwalten()"
                            @click="phaseVerschieben(p, s, 1)">
                        <Icon name="chevron" :size="14" class="icon-runter" />
                    </button>
                </div>

                <span class="phase__name">{{ s.name }}</span>
                <UiBadge :tone="ART_TON[s.art]">{{ STAGE_ART[s.art] }}</UiBadge>
                <div class="spacer" />
                <template v-if="darfVerwalten()">
                    <UiButton size="sm" @click="phaseBearbeiten(p, s)">Bearbeiten</UiButton>
                    <UiButton size="sm" variant="danger" @click="phaseLoeschenFragen(p, s)">Löschen</UiButton>
                </template>
            </div>

            <UiButton v-if="darfVerwalten()" class="phase__anlegen" @click="phaseNeu(p)">
                <Icon name="plus" :size="16" /> Phase hinzufügen
            </UiButton>
        </UiCard>

        <!-- Pipeline anlegen / umbenennen -->
        <UiSheet :offen="pipelineBlattOffen"
                 :titel="pipelineBearbeiteId ? 'Pipeline umbenennen' : 'Pipeline anlegen'"
                 bestaetigen="Speichern" :laeuft="pipelineSpeichert"
                 @schliessen="pipelineBlattOffen = false" @bestaetigen="pipelineSpeichern">
            <UiField v-model="pipelineEntwurf.name" label="Name" placeholder="z. B. Neukunden" />
            <p v-if="pipelineFehler" class="t-footnote fehler">{{ pipelineFehler }}</p>
        </UiSheet>

        <!-- Pipeline löschen -->
        <UiSheet :offen="!!pipelineZumLoeschen" titel="Pipeline löschen"
                 :text="pipelineZumLoeschen ? `„${pipelineZumLoeschen.name}“ wird mit allen ${pipelineZumLoeschen.stages.length} Phasen endgültig gelöscht. Das lässt sich nicht rückgängig machen.` : ''"
                 bestaetigen="Löschen" ton="danger" :laeuft="pipelineLoescht"
                 @schliessen="pipelineZumLoeschen = null" @bestaetigen="pipelineLoeschenBestaetigen">
            <p v-if="pipelineLoeschenFehler" class="t-footnote fehler">{{ pipelineLoeschenFehler }}</p>
        </UiSheet>

        <!-- Phase anlegen / bearbeiten -->
        <UiSheet :offen="phaseBlattOffen"
                 :titel="phaseBearbeiteId ? 'Phase bearbeiten' : 'Phase anlegen'"
                 bestaetigen="Speichern" :laeuft="phaseSpeichert"
                 @schliessen="phaseBlattOffen = false" @bestaetigen="phaseSpeichern">
            <UiField v-model="phaseEntwurf.name" label="Bezeichnung" placeholder="z. B. Angebot verschickt" />
            <label class="feld">
                <span class="feld__label">Art</span>
                <UiSegmented v-model="phaseEntwurf.art" :options="ART_OPTIONEN" />
            </label>
            <p class="t-footnote muted">{{ STAGE_ART_HINWEIS[phaseEntwurf.art] }}</p>
            <p v-if="phaseFehler" class="t-footnote fehler">{{ phaseFehler }}</p>
        </UiSheet>

        <!-- Phase löschen -->
        <UiSheet :offen="!!phaseZumLoeschen" titel="Phase löschen"
                 :text="phaseZumLoeschen ? `„${phaseZumLoeschen.phase.name}“ wird aus „${phaseZumLoeschen.pipeline.name}“ endgültig gelöscht. Das lässt sich nicht rückgängig machen.` : ''"
                 bestaetigen="Löschen" ton="danger" :laeuft="phaseLoescht"
                 @schliessen="phaseZumLoeschen = null" @bestaetigen="phaseLoeschenBestaetigen">
            <p v-if="phaseLoeschenFehler" class="t-footnote fehler">{{ phaseLoeschenFehler }}</p>
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.hinweis { background: var(--accent-quiet); border-color: transparent; }
.hinweis p { margin: 0; }
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }
.leer--phase { padding: var(--sp-5); }
.leer--phase p { margin: 0; }

.pipeline { display: flex; flex-direction: column; gap: var(--sp-3); }
.pipeline__kopf { display: flex; align-items: center; gap: var(--sp-3); flex-wrap: wrap; }
.pipeline__kopf p { margin: 0; }

.phase {
    display: flex; align-items: center; gap: var(--sp-3);
    padding: var(--sp-2) var(--sp-3);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    flex-wrap: wrap;
}
.phase__name { font-size: var(--text-subhead); font-weight: 500; }
.phase__pfeile { display: flex; flex-direction: column; gap: 2px; }
.pfeil {
    display: grid; place-items: center;
    width: 28px; height: 22px;
    border: 1px solid var(--separator);
    border-radius: var(--radius-s);
    background: var(--bg-input);
    color: var(--label-secondary);
    cursor: pointer;
    transition: background-color .16s ease, color .16s ease;
}
.pfeil:hover:not(:disabled) { background: var(--fill-quaternary); color: var(--label-primary); }
.pfeil:disabled { opacity: .35; cursor: not-allowed; }
.icon-hoch { transform: rotate(-90deg); }
.icon-runter { transform: rotate(90deg); }

.phase__anlegen { align-self: flex-start; }

.feld { display: flex; flex-direction: column; gap: var(--sp-2); }
.feld__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .pipeline__kopf { align-items: stretch; }
    .pipeline__kopf :deep(.btn) { min-height: 44px; }
    .phase { align-items: center; }
    .phase :deep(.btn) { min-height: 44px; }
    .pfeil { width: 44px; height: 44px; }
    .phase__anlegen { width: 100%; }
}
</style>
