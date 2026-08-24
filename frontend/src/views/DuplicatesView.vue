<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';
import { SICHERHEIT_LABEL } from '../labels.js';
import { datumZeile } from '../format.js';

const gruppen = ref([]);
const fehler = ref('');
const hinweis = ref('');
const laedt = ref(true);
const blatt = ref(null);      // { behalten, aufloesen }
const laeuft = ref(false);

async function laden() {
    laedt.value = true;
    try {
        const { data } = await api.get('/duplicates');
        gruppen.value = data.gruppen ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Dubletten konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

function fragen(behalten, aufloesen, gruppe) {
    // Kontakte, die nach diesem Zusammenführen in der Gruppe noch offen bleiben
    // (behalten und aufloesen sind dann erledigt).
    const restAnzahl = gruppe.kontakte.length - 2;
    blatt.value = { behalten, aufloesen, restAnzahl };
}

async function zusammenfuehren() {
    laeuft.value = true;
    try {
        const { data } = await api.post('/duplicates/merge', {
            keep: blatt.value.behalten.id,
            merge: blatt.value.aufloesen.id,
        });
        hinweis.value = `Zusammengeführt. Übernommen: ${data.uebernommeneFelder.length || 'nichts'} · `
            + `${data.umgehaengt.aktivitaeten} Verlaufseinträge und ${data.umgehaengt.vorgaenge} Vorgänge umgehängt.`;
        blatt.value = null;
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.error || 'Zusammenführen hat nicht geklappt.';
    } finally {
        laeuft.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header>
            <h2 class="t-large-title">Dubletten</h2>
            <p class="t-subhead">Mutmaßlich doppelte Kontakte — zusammengeführt wird nur, was du bestätigst.</p>
        </header>

        <p v-if="hinweis" class="t-footnote ok">{{ hinweis }}</p>
        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!laedt && !gruppen.length" class="leer">
            <p class="t-headline">Keine Dubletten gefunden</p>
            <p class="t-subhead">Geprüft wird auf gleiche E-Mail und gleichen Namen in derselben Firma.</p>
        </UiCard>

        <UiCard v-for="(g, i) in gruppen" :key="i" class="gruppe">
            <div class="row">
                <UiBadge :tone="g.sicherheit === 'sicher' ? 'quiet' : 'neutral'">
                    {{ SICHERHEIT_LABEL[g.sicherheit] }}
                </UiBadge>
                <span class="t-footnote muted">{{ g.grund }}</span>
            </div>

            <div class="karten">
                <div v-for="k in g.kontakte" :key="k.id" class="karte">
                    <RouterLink :to="`/kontakte/${k.id}`" class="karte__name">{{ k.name }}</RouterLink>
                    <p class="t-footnote muted">{{ k.email || 'keine E-Mail' }}</p>
                    <p class="t-footnote muted">{{ k.telefon || 'kein Telefon' }}</p>
                    <p class="t-footnote muted">{{ k.firma || 'keine Firma' }} · seit {{ datumZeile.format(new Date(k.erfasstAm)) }}</p>
                    <UiBadge :tone="k.darfKontaktiertWerden ? 'positive' : 'warn'">
                        {{ k.darfKontaktiertWerden ? 'Einwilligung' : 'keine Einwilligung' }}
                    </UiBadge>
                    <UiButton size="sm" variant="primary"
                              @click="fragen(k, g.kontakte.find((x) => x.id !== k.id), g)">
                        Diesen behalten
                    </UiButton>
                </div>
            </div>
        </UiCard>

        <UiSheet :offen="!!blatt" titel="Kontakte zusammenführen"
                 :text="blatt ? `„${blatt.aufloesen.name}“ wird aufgelöst. Leere Felder von „${blatt.behalten.name}“ werden daraus ergänzt, vorhandene bleiben unverändert. Verlauf und Vorgänge hängen anschließend am verbleibenden Kontakt. Das lässt sich nicht rückgängig machen.`
                     + (blatt.restAnzahl > 0
                         ? (blatt.restAnzahl === 1
                             ? ' In dieser Gruppe bleibt danach noch ein weiterer Kontakt offen.'
                             : ` In dieser Gruppe bleiben danach noch ${blatt.restAnzahl} weitere Kontakte offen.`)
                         : '') : ''"
                 bestaetigen="Zusammenführen" ton="danger" :laeuft="laeuft"
                 @schliessen="blatt = null" @bestaetigen="zusammenfuehren" />
    </div>
</template>

<style scoped>
header p { margin: var(--sp-1) 0 0; }
.gruppe { display: flex; flex-direction: column; gap: var(--sp-4); }
.karten { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: var(--sp-3); }
.karte {
    display: flex; flex-direction: column; gap: var(--sp-1);
    border: 1px solid var(--separator); border-radius: var(--radius-m); padding: var(--sp-4);
}
.karte p { margin: 0; }
.karte__name { font-size: var(--text-subhead); font-weight: 600; color: inherit; text-decoration: none; }
.karte__name:hover { color: var(--accent); }
.karte :deep(.btn) { margin-top: var(--sp-2); min-height: 44px; }
.karte :deep(.badge) { align-self: flex-start; margin-top: var(--sp-1); }
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }
</style>
