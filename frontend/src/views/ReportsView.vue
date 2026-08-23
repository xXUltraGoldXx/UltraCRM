<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../api';
import UiCard from '../components/ui/UiCard.vue';

const PHASE = { neu: 'Neu', qualifiziert: 'Qualifiziert', angebot: 'Angebot', verhandlung: 'Verhandlung', gewonnen: 'Gewonnen', verloren: 'Verloren' };
const QUELLE = { formular: 'Formular', telefon: 'Telefon', messe: 'Messe', empfehlung: 'Empfehlung', eigene_recherche: 'Recherche', import: 'Import', sonstiges: 'Sonstiges' };

const bericht = ref(null);
const fehler = ref('');
const geld = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });

onMounted(async () => {
    try {
        const { data } = await api.get('/reports/summary');
        bericht.value = data;
    } catch (e) {
        fehler.value = 'Die Auswertung konnte nicht geladen werden.';
    }
});

// Balkenbreite relativ zur groessten Phase — ohne Diagramm-Bibliothek.
const maxFunnel = computed(() => Math.max(1, ...(bericht.value?.funnel ?? []).map((f) => f.anzahl)));
const maxQuelle = computed(() => Math.max(1, ...(bericht.value?.quellen ?? []).map((q) => q.anzahl)));
</script>

<template>
    <div class="stack">
        <header>
            <h2 class="t-large-title">Auswertung</h2>
            <p class="t-subhead">Woher die Kontakte kommen und wo die Vorgänge stehen.</p>
        </header>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <template v-if="bericht">
            <div class="grid">
                <UiCard>
                    <p class="t-caption">Offener Pipeline-Wert</p>
                    <p class="t-large-title num">{{ geld.format(bericht.kennzahlen.offenerWert) }}</p>
                    <p class="t-footnote">{{ bericht.kennzahlen.offeneVorgaenge }} Vorgänge</p>
                </UiCard>
                <UiCard>
                    <p class="t-caption">Gewonnen</p>
                    <p class="t-large-title num">{{ geld.format(bericht.kennzahlen.gewonnenerWert) }}</p>
                    <p class="t-footnote">
                        <template v-if="bericht.kennzahlen.abschlussquote !== null">
                            {{ bericht.kennzahlen.abschlussquote }} % Abschlussquote
                        </template>
                        <template v-else>noch kein Vorgang abgeschlossen</template>
                    </p>
                </UiCard>
                <UiCard>
                    <p class="t-caption">Kontakte</p>
                    <p class="t-large-title num">{{ bericht.kennzahlen.kontakte }}</p>
                    <p class="t-footnote">{{ bericht.kennzahlen.kontaktierbar }} mit Einwilligung</p>
                </UiCard>
            </div>

            <UiCard>
                <p class="t-caption">Vorgänge je Phase</p>
                <div v-for="f in bericht.funnel" :key="f.phase" class="zeile">
                    <span class="label t-subhead">{{ PHASE[f.phase] }}</span>
                    <span class="balken" :style="{ width: `${(f.anzahl / maxFunnel) * 100}%` }"
                          :class="{ 'balken--leer': !f.anzahl }" />
                    <span class="wert t-footnote">{{ f.anzahl }} · {{ geld.format(f.wert) }}</span>
                </div>
            </UiCard>

            <UiCard>
                <p class="t-caption">Herkunft der Kontakte</p>
                <div v-for="q in bericht.quellen" :key="q.quelle" class="zeile">
                    <span class="label t-subhead">{{ QUELLE[q.quelle] || q.quelle }}</span>
                    <span class="balken balken--alt" :style="{ width: `${(q.anzahl / maxQuelle) * 100}%` }" />
                    <span class="wert t-footnote">{{ q.anzahl }}</span>
                </div>
                <p v-if="!bericht.quellen.length" class="t-footnote muted">Noch keine Kontakte erfasst.</p>
            </UiCard>
        </template>
    </div>
</template>

<style scoped>
header p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: var(--sp-4); }
.num { margin: var(--sp-2) 0 var(--sp-1); font-variant-numeric: tabular-nums; }
.t-caption { margin: 0 0 var(--sp-4); }
p { margin: 0; }

.zeile { display: grid; grid-template-columns: 130px 1fr 150px; align-items: center; gap: var(--sp-3); padding: var(--sp-2) 0; }
.label { color: var(--label-secondary); }
.balken {
    height: 10px;
    min-width: 3px;
    border-radius: var(--radius-pill);
    background: var(--accent);
    transition: width .4s ease;
}
.balken--alt { background: var(--success); }
.balken--leer { background: var(--fill-tertiary); }
.wert { text-align: right; font-variant-numeric: tabular-nums; }

@media (max-width: 700px) { .zeile { grid-template-columns: 100px 1fr 90px; } }
</style>
