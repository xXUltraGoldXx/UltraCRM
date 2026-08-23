<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import UiCard from '../components/ui/UiCard.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';

const zeitraum = ref('30');
const kontakte = ref(null);
const offeneDeals = ref(null);
const offenerWert = ref(null);
const faellig = ref(null);
const fehler = ref('');

const geld = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
const anzahl = (d) => d['hydra:totalItems'] ?? d.totalItems ?? 0;

onMounted(async () => {
    try {
        const [k, d, a] = await Promise.all([
            api.get('/contacts', { params: { itemsPerPage: 1 } }),
            api.get('/deals', { params: { itemsPerPage: 200 } }),
            api.get('/activities', { params: { done: false, 'exists[dueAt]': true, itemsPerPage: 1 } }),
        ]);
        kontakte.value = anzahl(k.data);
        const deals = d.data['hydra:member'] ?? d.data.member ?? [];
        const offen = deals.filter((x) => x.open);
        offeneDeals.value = offen.length;
        offenerWert.value = geld.format(offen.reduce((s, x) => s + Number(x.value || 0), 0));
        faellig.value = anzahl(a.data);
    } catch (e) {
        fehler.value = 'Kennzahlen konnten nicht geladen werden.';
    }
});
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Übersicht</h2>
                <p class="t-subhead">Was gerade in deinem Vertrieb passiert.</p>
            </div>
            <UiSegmented v-model="zeitraum" :options="[
                { value: '7', label: '7 Tage' },
                { value: '30', label: '30 Tage' },
                { value: '365', label: 'Jahr' },
            ]" />
        </header>

        <div class="grid">
            <UiCard>
                <p class="t-caption">Kontakte</p>
                <p class="t-large-title num">{{ kontakte ?? '–' }}</p>
                <p class="t-footnote">im Bestand</p>
            </UiCard>
            <UiCard>
                <p class="t-caption">Offene Vorgänge</p>
                <p class="t-large-title num">{{ offeneDeals ?? '–' }}</p>
                <p class="t-footnote">{{ offenerWert ?? '' }} in der Pipeline</p>
            </UiCard>
            <UiCard>
                <p class="t-caption">Wiedervorlagen</p>
                <p class="t-large-title num">{{ faellig ?? '–' }}</p>
                <p class="t-footnote">offen und terminiert</p>
            </UiCard>
        </div>

        <UiCard v-if="fehler">
            <p class="t-body">{{ fehler }}</p>
        </UiCard>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: var(--sp-4); }
.num { margin: var(--sp-2) 0 var(--sp-1); font-variant-numeric: tabular-nums; }
.t-caption { margin: 0; }

@media (max-width: 700px) {
    .head { flex-direction: column; align-items: stretch; }
    .head :deep(.seg) { display: grid; grid-template-columns: repeat(3, 1fr); }

    /* Auf Handy-Höhe zählt jede Zeile: Kennzahl und Beschriftung stehen
       nebeneinander statt in einer hohen Karte untereinander. */
    .grid { grid-template-columns: 1fr; gap: var(--sp-2); }
    .grid :deep(.card) {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        row-gap: 2px;
        padding: var(--sp-4);
    }
    .grid :deep(.card) .t-caption { grid-column: 1; margin: 0; }
    .grid :deep(.card) .num { grid-column: 2; grid-row: 1 / span 2; margin: 0; font-size: var(--text-title-1); }
    .grid :deep(.card) .t-footnote { grid-column: 1; }

}
</style>
