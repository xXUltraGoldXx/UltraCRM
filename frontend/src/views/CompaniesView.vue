<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const firmen = ref([]);
const zaehler = ref({});   // firmaId -> Anzahl Personen
const suche = ref('');
const fehler = ref('');
const anlegen = ref(false);
const speichert = ref(false);
const entwurf = ref({ name: '', street: '', zipCode: '', city: '', website: '' });

async function laden() {
    try {
        const { data } = await api.get('/companies', {
            params: suche.value.trim() ? { name: suche.value.trim() } : {},
        });
        firmen.value = data['hydra:member'] ?? data.member ?? [];
        fehler.value = '';

        // Personenzahl je Firma — ein Aufruf je Firma waere zu viel, deshalb
        // einmal alle Kontakte holen und zaehlen.
        const { data: k } = await api.get('/contacts', { params: { itemsPerPage: 200 } });
        const alle = k['hydra:member'] ?? k.member ?? [];
        const map = {};
        alle.forEach((c) => {
            const id = c.company?.id;
            if (id) map[id] = (map[id] || 0) + 1;
        });
        zaehler.value = map;
    } catch (e) {
        fehler.value = 'Die Firmen konnten nicht geladen werden.';
    }
}
let timer;
watch(suche, () => { clearTimeout(timer); timer = setTimeout(laden, 250); });
onMounted(laden);

async function speichern() {
    speichert.value = true;
    try {
        const n = Object.fromEntries(Object.entries(entwurf.value).filter(([, v]) => v !== ''));
        await api.post('/companies', n, { headers: { 'Content-Type': 'application/ld+json' } });
        anlegen.value = false;
        entwurf.value = { name: '', street: '', zipCode: '', city: '', website: '' };
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Firmen</h2>
                <p class="t-subhead">{{ firmen.length }} {{ firmen.length === 1 ? 'Firma' : 'Firmen' }}</p>
            </div>
            <UiButton variant="primary" @click="anlegen = true">
                <Icon name="plus" :size="16" /> Firma anlegen
            </UiButton>
        </header>

        <label class="suche">
            <Icon name="search" :size="17" />
            <input v-model="suche" type="search" placeholder="Firma suchen" />
        </label>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!firmen.length" class="leer">
            <p class="t-headline">Noch keine Firma</p>
            <p class="t-subhead">Lege eine Firma an und ordne ihr danach Ansprechpartner zu.</p>
        </UiCard>

        <div v-else class="liste">
            <RouterLink v-for="f in firmen" :key="f.id" :to="`/firmen/${f.id}`" class="firma">
                <div class="stack tight">
                    <span class="firma__name">{{ f.name }}</span>
                    <span class="t-footnote muted">
                        {{ [f.zipCode, f.city].filter(Boolean).join(' ') || 'ohne Anschrift' }}
                    </span>
                </div>
                <span class="spacer" />
                <span class="t-footnote">{{ zaehler[f.id] || 0 }} Personen</span>
                <Icon name="chevron" :size="16" class="pfeil" />
            </RouterLink>
        </div>

        <UiSheet :offen="anlegen" titel="Firma anlegen" bestaetigen="Speichern"
                 :laeuft="speichert" @schliessen="anlegen = false" @bestaetigen="speichern">
            <UiField v-model="entwurf.name" label="Firmenname" placeholder="z. B. Müller GmbH" />
            <UiField v-model="entwurf.street" label="Straße" />
            <div class="zeile">
                <UiField v-model="entwurf.zipCode" label="PLZ" />
                <UiField v-model="entwurf.city" label="Ort" />
            </div>
            <UiField v-model="entwurf.website" label="Website" placeholder="https://…" />
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }
.tight { gap: 2px; }

.suche {
    display: flex; align-items: center; gap: var(--sp-2);
    background: var(--bg-elevated); border: 1px solid var(--separator);
    border-radius: var(--radius-pill); padding: 8px 14px;
    color: var(--label-tertiary); min-height: 44px;
}
.suche input { border: 0; background: transparent; outline: none; font-family: inherit; font-size: var(--text-subhead); color: var(--label-primary); width: 100%; }

.liste { display: flex; flex-direction: column; gap: var(--sp-2); }
.firma {
    display: flex; align-items: center; gap: var(--sp-3);
    background: var(--bg-elevated); border: 1px solid var(--separator);
    border-radius: var(--radius-m); padding: var(--sp-4) var(--sp-5);
    text-decoration: none; color: inherit; min-height: 60px;
}
.firma:hover { border-color: var(--accent); text-decoration: none; }
.firma__name { font-size: var(--text-body); font-weight: 600; }
.pfeil { color: var(--label-tertiary); }

.zeile { display: grid; grid-template-columns: 1fr 2fr; gap: var(--sp-3); }
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
}
</style>
