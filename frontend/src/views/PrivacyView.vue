<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiField from '../components/ui/UiField.vue';
import UiSheet from '../components/ui/UiSheet.vue';
import { datum } from '../format.js';

const kontakte = ref([]);
const protokoll = ref([]);
const faellig = ref([]);
const fehler = ref('');
const hinweis = ref('');

async function laden() {
    try {
        const [k, p, f] = await Promise.all([
            api.get('/contacts'),
            api.get('/deletion_logs'),
            api.get('/privacy/due-deletions'),
        ]);
        kontakte.value = k.data['hydra:member'] ?? k.data.member ?? [];
        protokoll.value = p.data['hydra:member'] ?? p.data.member ?? [];
        faellig.value = f.data.kontakte ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = 'Die Daten konnten nicht geladen werden.';
    }
}
onMounted(laden);

/* Auskunft als Datei — der Browser laedt sie ueber einen Blob, damit der
   Authorization-Header mitgeht (ein einfacher Link kann das nicht). */
async function auskunft(k) {
    try {
        const { data } = await api.get(`/privacy/contacts/${k.id}/export`);
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `auskunft-${k.lastName || k.id}.json`;
        a.click();
        URL.revokeObjectURL(url);
        hinweis.value = `Auskunft für ${k.displayName} erstellt.`;
    } catch (e) {
        fehler.value = 'Die Auskunft konnte nicht erstellt werden.';
    }
}

async function widerrufen(k) {
    try {
        await api.post(`/privacy/contacts/${k.id}/withdraw`);
        hinweis.value = `Einwilligung von ${k.displayName} widerrufen.`;
        await laden();
    } catch (e) {
        fehler.value = 'Der Widerruf hat nicht geklappt.';
    }
}

/* Löschen ist unumkehrbar — deshalb ein eigenes Blatt mit Warnung und
   Pflichtgrund statt eines Browser-Dialogs, den man wegklickt. */
const loeschBlatt = ref(null);
const loeschGrund = ref('');
const loeschLaeuft = ref(false);

function loeschenFragen(k) {
    loeschGrund.value = '';
    loeschBlatt.value = k;
}

async function loeschenBestaetigen() {
    if (!loeschGrund.value.trim()) return;
    loeschLaeuft.value = true;
    try {
        const { data } = await api.post(`/privacy/contacts/${loeschBlatt.value.id}/erase`, { reason: loeschGrund.value.trim() });
        hinweis.value = `Gelöscht. Mitgelöschte Datensätze: ${data.protokoll.mitgeloeschteDatensaetze}.`;
        loeschBlatt.value = null;
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.error || 'Die Löschung hat nicht geklappt.';
    } finally {
        loeschLaeuft.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header>
            <h2 class="t-large-title">Einwilligungen</h2>
            <p class="t-subhead">Auskunft, Widerruf und Löschung — mit Nachweis.</p>
        </header>

        <p v-if="hinweis" class="t-footnote ok">{{ hinweis }}</p>
        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="faellig.length" class="faellig">
            <p class="t-headline">{{ faellig.length }} Löschung fällig</p>
            <p class="t-subhead">Die Aufbewahrungsfrist ist abgelaufen: {{ faellig.map((f) => f.name).join(', ') }}</p>
        </UiCard>

        <UiCard>
            <p class="t-caption">Kontakte</p>
            <table>
                <tbody>
                    <tr v-for="k in kontakte" :key="k.id">
                        <td>
                            <span class="name">{{ k.displayName }}</span>
                            <span class="t-footnote">Herkunft: {{ k.source }}</span>
                        </td>
                        <td>
                            <UiBadge :tone="k.contactable ? 'positive' : 'warn'">
                                {{ k.contactable ? 'Einwilligung liegt vor' : 'Keine Einwilligung' }}
                            </UiBadge>
                        </td>
                        <td class="aktionen">
                            <UiButton size="sm" @click="auskunft(k)">Auskunft</UiButton>
                            <UiButton v-if="k.contactable" size="sm" @click="widerrufen(k)">Widerrufen</UiButton>
                            <UiButton size="sm" variant="danger" @click="loeschenFragen(k)">Löschen</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!kontakte.length"><td colspan="3" class="leer t-footnote">Keine Kontakte vorhanden.</td></tr>
                </tbody>
            </table>
        </UiCard>

        <UiCard>
            <p class="t-caption">Löschprotokoll</p>
            <p class="t-footnote muted spacer-b">
                Nachweis erfolgter Löschungen. Enthält bewusst keine Personendaten —
                der Datensatz wird nur pseudonym referenziert.
            </p>
            <table>
                <tbody>
                    <tr v-for="p in protokoll" :key="p.id">
                        <td>
                            <span class="name">{{ p.reason }}</span>
                            <span class="t-footnote">{{ p.subjectRef.slice(0, 12) }}… · {{ p.relatedCount }} mitgelöscht</span>
                        </td>
                        <td class="t-footnote muted">{{ p.deletedBy }}</td>
                        <td class="t-footnote muted">{{ datum.format(new Date(p.deletedAt)) }}</td>
                    </tr>
                    <tr v-if="!protokoll.length"><td colspan="3" class="leer t-footnote">Noch nichts gelöscht.</td></tr>
                </tbody>
            </table>
        </UiCard>

        <UiSheet :offen="!!loeschBlatt"
                 :titel="`„${loeschBlatt?.displayName ?? ''}“ endgültig löschen?`"
                 text="Die Daten sind danach nicht wiederherstellbar. Im Protokoll bleibt nur der Nachweis der Löschung — ohne Personendaten."
                 bestaetigen="Endgültig löschen" ton="danger" :laeuft="loeschLaeuft"
                 @schliessen="loeschBlatt = null" @bestaetigen="loeschenBestaetigen">
            <UiField v-model="loeschGrund" label="Grund der Löschung"
                     placeholder="z. B. Betroffene hat Löschung verlangt"
                     hint="Pflichtangabe — sie steht später im Protokoll." />
        </UiSheet>
    </div>
</template>

<style scoped>
header p { margin: var(--sp-1) 0 0; }
.ok { color: var(--success); }
.fehler { color: var(--danger); }
.faellig { border-color: var(--warning); }
.faellig p { margin: 0 0 var(--sp-1); }
.spacer-b { margin: 0 0 var(--sp-3); }
.t-caption { margin: 0 0 var(--sp-3); }

table { width: 100%; border-collapse: collapse; }
td { padding: var(--sp-3) 0; border-bottom: 1px solid var(--separator); font-size: var(--text-subhead); vertical-align: middle; }
tbody tr:last-child td { border-bottom: 0; }
.name { display: block; font-weight: 600; }
td .t-footnote { display: block; }
.aktionen { display: flex; gap: var(--sp-2); justify-content: flex-end; flex-wrap: wrap; }
.aktionen :deep(.btn) { min-height: 40px; }

@media (max-width: 760px) {
    table, tbody, tr, td { display: block; }
    tbody tr {
        border: 1px solid var(--separator);
        border-radius: var(--radius-m);
        padding: var(--sp-4);
        margin-bottom: var(--sp-2);
    }
    td { border: 0; padding: 0 0 var(--sp-2); }
    .aktionen { justify-content: stretch; padding-bottom: 0; }
    .aktionen :deep(.btn) { flex: 1; min-height: 44px; }
}
.leer { text-align: center; color: var(--label-tertiary); }
</style>
