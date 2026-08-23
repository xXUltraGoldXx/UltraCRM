<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';

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
async function ablegen(phase) {
    const id = ziehtId.value;
    ziehtId.value = null;
    const deal = deals.value.find((d) => d.id === id);
    if (!deal || deal.stage === phase) return;

    const patch = { stage: phase };
    if (phase === 'verloren') {
        const grund = window.prompt('Warum ging der Vorgang verloren?');
        if (!grund) return;
        patch.lostReason = grund;
    }

    const vorher = deal.stage;
    deal.stage = phase; // sofortige Rueckmeldung
    try {
        await api.patch(`/deals/${id}`, patch, { headers: { 'Content-Type': 'application/merge-patch+json' } });
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
            <UiButton variant="primary" @click="formOffen = !formOffen">
                <Icon name="plus" :size="16" /> Vorgang anlegen
            </UiButton>
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

        <div class="board">
            <section v-for="p in PHASEN" :key="p.key" class="spalte"
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
                </article>

                <p v-if="!(nachPhase[p.key] || []).length" class="leer t-footnote">Nichts hier</p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }

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
