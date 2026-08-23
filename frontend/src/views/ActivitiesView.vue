<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import { ART } from '../labels.js';
import { datumZeile } from '../format.js';

const auth = useAuthStore();
const eintraege = ref([]);
const laedt = ref(true);
const fehler = ref('');
const sicht = ref('offen');
const formOffen = ref(false);
const speichert = ref(false);
const formFehler = ref('');
const leer = () => ({ type: 'aufgabe', subject: '', body: '', dueAt: '' });
const entwurf = ref(leer());

const datum = datumZeile;

async function laden() {
    laedt.value = true;
    try {
        const params = {};
        if (sicht.value === 'offen') { params.done = false; params['exists[dueAt]'] = true; }
        if (sicht.value === 'erledigt') params.done = true;
        const { data } = await api.get('/activities', { params });
        eintraege.value = data['hydra:member'] ?? data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Aktivitäten konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
watch(sicht, laden);
onMounted(laden);

async function abhaken(a) {
    const vorher = a.done;
    a.done = true; // sofortige Rueckmeldung
    try {
        await api.patch(`/activities/${a.id}`, { done: true }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        await laden();
    } catch (e) {
        a.done = vorher;
        fehler.value = 'Das Abhaken hat nicht geklappt.';
    }
}

async function speichern() {
    speichert.value = true;
    formFehler.value = '';
    try {
        const n = { type: entwurf.value.type, subject: entwurf.value.subject };
        if (entwurf.value.body) n.body = entwurf.value.body;
        if (entwurf.value.dueAt) n.dueAt = new Date(entwurf.value.dueAt).toISOString();
        await api.post('/activities', n, { headers: { 'Content-Type': 'application/ld+json' } });
        formOffen.value = false;
        entwurf.value = leer();
        await laden();
    } catch (e) {
        formFehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

const ueberfaellig = computed(() => eintraege.value.filter((a) => a.overdue).length);
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Aktivitäten</h2>
                <p class="t-subhead">
                    <template v-if="ueberfaellig">{{ ueberfaellig }} überfällig</template>
                    <template v-else>Nichts überfällig</template>
                </p>
            </div>
            <UiButton variant="primary" v-if="auth.darf('activities.manage')" @click="formOffen = !formOffen">
                <Icon name="plus" :size="16" /> Eintrag anlegen
            </UiButton>
        </header>

        <UiCard v-if="formOffen" class="form">
            <div class="form__grid">
                <label class="field">
                    <span class="field__label">Art</span>
                    <select v-model="entwurf.type">
                        <option v-for="(l, k) in ART" :key="k" :value="k">{{ l }}</option>
                    </select>
                </label>
                <UiField v-model="entwurf.subject" label="Betreff" placeholder="z. B. Angebot nachfassen" />
                <UiField v-model="entwurf.dueAt" label="Fällig am" type="datetime-local"
                         hint="Leer lassen für einen reinen Verlaufseintrag." />
            </div>
            <UiField v-model="entwurf.body" label="Notiz" />
            <p v-if="formFehler" class="t-footnote fehler">{{ formFehler }}</p>
            <div class="row form__actions">
                <UiButton variant="quiet" @click="formOffen = false">Abbrechen</UiButton>
                <UiButton variant="primary" :disabled="speichert || !entwurf.subject" @click="speichern">Speichern</UiButton>
            </div>
        </UiCard>

        <UiSegmented v-model="sicht" :options="[
            { value: 'offen', label: 'Wiedervorlagen' },
            { value: 'alle', label: 'Verlauf' },
            { value: 'erledigt', label: 'Erledigt' },
        ]" />

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!laedt && !eintraege.length" class="leer">
            <p class="t-headline">Nichts zu tun</p>
            <p class="t-subhead">Hier stehen Anrufe, Notizen und Wiedervorlagen.</p>
        </UiCard>

        <div v-else class="liste">
            <article v-for="a in eintraege" :key="a.id" class="eintrag">
                <div class="eintrag__text">
                    <div class="row">
                        <UiBadge tone="quiet">{{ ART[a.type] }}</UiBadge>
                        <UiBadge v-if="a.overdue" tone="warn">Überfällig</UiBadge>
                        <UiBadge v-else-if="a.done" tone="positive">Erledigt</UiBadge>
                    </div>
                    <p class="betreff">{{ a.subject }}</p>
                    <p v-if="a.body" class="t-footnote">{{ a.body }}</p>
                    <p v-if="a.dueAt" class="t-footnote muted">Fällig: {{ datum.format(new Date(a.dueAt)) }}</p>
                </div>
                <UiButton v-if="!a.done" size="sm" @click="abhaken(a)">
                    <Icon name="check" :size="15" /> Erledigt
                </UiButton>
            </article>
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

.liste { display: flex; flex-direction: column; gap: var(--sp-2); }
.eintrag {
    display: flex; align-items: center; gap: var(--sp-4);
    background: var(--bg-elevated); border: 1px solid var(--separator);
    border-radius: var(--radius-m); padding: var(--sp-4) var(--sp-5);
}
.eintrag__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--sp-1); }
.eintrag p { margin: 0; }
.betreff { font-size: var(--text-subhead); font-weight: 600; }

@media (max-width: 700px) {
    .eintrag { flex-direction: column; align-items: stretch; gap: var(--sp-3); }
    .eintrag :deep(.btn) { width: 100%; min-height: 44px; }
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .form__grid { grid-template-columns: 1fr; }
    :deep(.seg) { display: grid; grid-template-columns: repeat(3, 1fr); width: 100%; }
}

.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }
</style>
