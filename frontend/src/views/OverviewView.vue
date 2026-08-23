<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import UiCard from '../components/ui/UiCard.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';

const zeitraum = ref('30');
const kontakte = ref(null);
const fehler = ref('');

onMounted(async () => {
    try {
        const { data } = await api.get('/customers', { params: { itemsPerPage: 1 } });
        kontakte.value = data['hydra:totalItems'] ?? data.totalItems ?? 0;
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
                <p class="t-caption">Offene Deals</p>
                <p class="t-large-title num">–</p>
                <p class="t-footnote">folgt mit der Pipeline</p>
            </UiCard>
            <UiCard>
                <p class="t-caption">Fällig heute</p>
                <p class="t-large-title num">–</p>
                <p class="t-footnote">folgt mit den Aktivitäten</p>
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
</style>
