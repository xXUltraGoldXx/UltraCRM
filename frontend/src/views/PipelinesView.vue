<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { STAGE_ART, STAGE_ART_HINWEIS } from '../labels.js';
import { nachricht } from '../composables/nachricht.js';
import { useVerwaltungsBlatt } from '../composables/useVerwaltungsBlatt.js';
import { useLoeschenBestaetigung } from '../composables/useLoeschenBestaetigung.js';
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

/* ---------------------------------------------------------------- Pipeline */

const pipelineBlatt = useVerwaltungsBlatt('/pipelines', {
    leererEntwurf: () => ({ name: '' }),
    patchDaten: (entwurf) => ({ name: entwurf.name }),
    postDaten: (entwurf) => ({ name: entwurf.name, position: pipelines.value.length + 1 }),
    nachSpeichern: laden,
});

// Bestaetigungsblatt vor dem Loeschen — unumkehrbarer Schritt, siehe
// Dubletten-Zusammenfuehrung fuer dasselbe Muster.
const pipelineLoeschen = useLoeschenBestaetigung('/pipelines', {
    holeId: (p) => p.id,
    nachLoeschen: laden,
});

/* -------------------------------------------------------------------- Phase */

const phaseBlatt = useVerwaltungsBlatt('/stages', {
    leererEntwurf: () => ({ name: '', art: 'offen' }),
    patchDaten: (entwurf) => ({ name: entwurf.name, art: entwurf.art }),
    postDaten: (entwurf, pipelineId) => {
        const pipeline = pipelines.value.find((p) => p.id === pipelineId);
        return {
            name: entwurf.name,
            art: entwurf.art,
            pipeline: `/api/pipelines/${pipelineId}`,
            position: (pipeline?.stages.length ?? 0) + 1,
        };
    },
    nachSpeichern: laden,
});

const phaseLoeschen = useLoeschenBestaetigung('/stages', {
    holeId: (z) => z.phase.id,
    nachLoeschen: laden,
});

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
            <UiButton v-if="darfVerwalten()" variant="primary" @click="pipelineBlatt.neu()">
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
                    <UiButton size="sm" @click="pipelineBlatt.bearbeiten(p.id, { name: p.name })">Umbenennen</UiButton>
                    <UiButton size="sm" variant="danger" @click="pipelineLoeschen.fragen(p)">Löschen</UiButton>
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
                    <UiButton size="sm" @click="phaseBlatt.bearbeiten(s.id, { name: s.name, art: s.art }, p.id)">Bearbeiten</UiButton>
                    <UiButton size="sm" variant="danger" @click="phaseLoeschen.fragen({ pipeline: p, phase: s })">Löschen</UiButton>
                </template>
            </div>

            <UiButton v-if="darfVerwalten()" class="phase__anlegen" @click="phaseBlatt.neu(p.id)">
                <Icon name="plus" :size="16" /> Phase hinzufügen
            </UiButton>
        </UiCard>

        <!-- Pipeline anlegen / umbenennen -->
        <UiSheet :offen="pipelineBlatt.offen"
                 :titel="pipelineBlatt.bearbeiteId ? 'Pipeline umbenennen' : 'Pipeline anlegen'"
                 bestaetigen="Speichern" :laeuft="pipelineBlatt.speichert"
                 @schliessen="pipelineBlatt.offen = false" @bestaetigen="pipelineBlatt.speichern">
            <UiField v-model="pipelineBlatt.entwurf.name" label="Name" placeholder="z. B. Neukunden" />
            <p v-if="pipelineBlatt.fehler" class="t-footnote fehler">{{ pipelineBlatt.fehler }}</p>
        </UiSheet>

        <!-- Pipeline löschen -->
        <UiSheet :offen="!!pipelineLoeschen.zumLoeschen" titel="Pipeline löschen"
                 :text="pipelineLoeschen.zumLoeschen ? `„${pipelineLoeschen.zumLoeschen.name}“ wird mit allen ${pipelineLoeschen.zumLoeschen.stages.length} Phasen endgültig gelöscht. Das lässt sich nicht rückgängig machen.` : ''"
                 bestaetigen="Löschen" ton="danger" :laeuft="pipelineLoeschen.loescht"
                 @schliessen="pipelineLoeschen.zumLoeschen = null" @bestaetigen="pipelineLoeschen.bestaetigen">
            <p v-if="pipelineLoeschen.fehler" class="t-footnote fehler">{{ pipelineLoeschen.fehler }}</p>
        </UiSheet>

        <!-- Phase anlegen / bearbeiten -->
        <UiSheet :offen="phaseBlatt.offen"
                 :titel="phaseBlatt.bearbeiteId ? 'Phase bearbeiten' : 'Phase anlegen'"
                 bestaetigen="Speichern" :laeuft="phaseBlatt.speichert"
                 @schliessen="phaseBlatt.offen = false" @bestaetigen="phaseBlatt.speichern">
            <UiField v-model="phaseBlatt.entwurf.name" label="Bezeichnung" placeholder="z. B. Angebot verschickt" />
            <label class="feld">
                <span class="feld__label">Art</span>
                <UiSegmented v-model="phaseBlatt.entwurf.art" :options="ART_OPTIONEN" />
            </label>
            <p class="t-footnote muted">{{ STAGE_ART_HINWEIS[phaseBlatt.entwurf.art] }}</p>
            <p v-if="phaseBlatt.fehler" class="t-footnote fehler">{{ phaseBlatt.fehler }}</p>
        </UiSheet>

        <!-- Phase löschen -->
        <UiSheet :offen="!!phaseLoeschen.zumLoeschen" titel="Phase löschen"
                 :text="phaseLoeschen.zumLoeschen ? `„${phaseLoeschen.zumLoeschen.phase.name}“ wird aus „${phaseLoeschen.zumLoeschen.pipeline.name}“ endgültig gelöscht. Das lässt sich nicht rückgängig machen.` : ''"
                 bestaetigen="Löschen" ton="danger" :laeuft="phaseLoeschen.loescht"
                 @schliessen="phaseLoeschen.zumLoeschen = null" @bestaetigen="phaseLoeschen.bestaetigen">
            <p v-if="phaseLoeschen.fehler" class="t-footnote fehler">{{ phaseLoeschen.fehler }}</p>
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
