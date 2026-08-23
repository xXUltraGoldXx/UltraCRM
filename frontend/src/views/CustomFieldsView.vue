<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const TYPEN = { text: 'Text', zahl: 'Zahl', datum: 'Datum', auswahl: 'Auswahl', janein: 'Ja/Nein' };
const BEREICHE = { contact: 'Kontakte', company: 'Firmen', deal: 'Vorgänge' };

const felder = ref([]);
const fehler = ref('');
const blattOffen = ref(false);
const speichert = ref(false);
const leer = () => ({ entityType: 'contact', label: '', fieldKey: '', type: 'text', optionenText: '', required: false });
const entwurf = ref(leer());

async function laden() {
    try {
        const { data } = await api.get('/custom_field_definitions');
        felder.value = data['hydra:member'] ?? data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Zusatzfelder konnten nicht geladen werden.';
    }
}
onMounted(laden);

/* Schlüssel aus der Bezeichnung ableiten — er darf sich später nicht mehr
   ändern, sonst verlieren bestehende Datensätze ihren Wert. */
function schluesselAus(text) {
    return text.toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60)
        .replace(/^([0-9])/, 'f$1');
}

async function speichern() {
    speichert.value = true;
    fehler.value = '';
    try {
        const n = {
            entityType: entwurf.value.entityType,
            fieldKey: entwurf.value.fieldKey || schluesselAus(entwurf.value.label),
            label: entwurf.value.label,
            type: entwurf.value.type,
            required: entwurf.value.required,
            position: felder.value.length + 1,
        };
        if (entwurf.value.type === 'auswahl') {
            n.options = entwurf.value.optionenText.split('\n').map((z) => z.trim()).filter(Boolean);
        }
        await api.post('/custom_field_definitions', n, { headers: { 'Content-Type': 'application/ld+json' } });
        blattOffen.value = false;
        entwurf.value = leer();
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description']
            || e?.response?.data?.detail
            || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Zusatzfelder</h2>
                <p class="t-subhead">Eigene Felder für Kontakte, Firmen und Vorgänge.</p>
            </div>
            <UiButton variant="primary" @click="blattOffen = true">
                <Icon name="plus" :size="16" /> Feld anlegen
            </UiButton>
        </header>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!felder.length" class="leer">
            <p class="t-headline">Noch keine Zusatzfelder</p>
            <p class="t-subhead">Lege ein Feld an — es erscheint danach beim Bearbeiten der Datensätze.</p>
        </UiCard>

        <UiCard v-for="f in felder" :key="f.id" class="zeile">
            <div class="stack tight">
                <span class="name">
                    {{ f.label }}
                    <UiBadge v-if="f.required" tone="warn">Pflicht</UiBadge>
                </span>
                <span class="t-footnote muted">
                    {{ BEREICHE[f.entityType] }} · {{ TYPEN[f.type] }} · Schlüssel {{ f.fieldKey }}
                    <template v-if="f.options"> · {{ f.options.join(', ') }}</template>
                </span>
            </div>
        </UiCard>

        <UiSheet :offen="blattOffen" titel="Zusatzfeld anlegen" bestaetigen="Anlegen"
                 :laeuft="speichert" @schliessen="blattOffen = false" @bestaetigen="speichern">
            <label class="feld">
                <span class="feld__label">Für welchen Bereich</span>
                <select v-model="entwurf.entityType">
                    <option v-for="(t, k) in BEREICHE" :key="k" :value="k">{{ t }}</option>
                </select>
            </label>
            <UiField v-model="entwurf.label" label="Bezeichnung" placeholder="z. B. Kundennummer" />
            <label class="feld">
                <span class="feld__label">Art</span>
                <select v-model="entwurf.type">
                    <option v-for="(t, k) in TYPEN" :key="k" :value="k">{{ t }}</option>
                </select>
            </label>
            <UiField v-if="entwurf.type === 'auswahl'" v-model="entwurf.optionenText"
                     label="Möglichkeiten" hint="Eine je Zeile, mindestens zwei." />
            <label class="haken">
                <input v-model="entwurf.required" type="checkbox" />
                <span>Pflichtfeld</span>
            </label>
            <p class="t-footnote muted">
                Schlüssel: <code>{{ entwurf.fieldKey || schluesselAus(entwurf.label) || '—' }}</code>
                — er bleibt fest, damit bestehende Einträge ihren Wert behalten.
            </p>
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.zeile { display: flex; align-items: center; gap: var(--sp-4); }
.zeile p, .zeile span { margin: 0; }
.name { font-size: var(--text-body); font-weight: 600; display: flex; align-items: center; gap: var(--sp-2); }
.feld { display: flex; flex-direction: column; gap: var(--sp-2); }
.feld__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }
select {
    font-family: inherit; font-size: var(--text-body); color: var(--label-primary);
    background: var(--bg-input); border: 1px solid var(--separator);
    border-radius: var(--radius-m); padding: 11px 14px; min-height: 44px;
}
.haken { display: flex; align-items: center; gap: var(--sp-3); font-size: var(--text-subhead); min-height: 44px; }
.haken input { width: 20px; height: 20px; accent-color: var(--accent); }
code { font-family: var(--font-mono); font-size: var(--text-caption); }
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }

@media (max-width: 700px) { .head :deep(.btn) { width: 100%; min-height: 46px; } }
</style>
