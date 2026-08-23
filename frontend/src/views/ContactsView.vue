<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';
import UiBadge from '../components/ui/UiBadge.vue';

const STATUS_LABEL = {
    neu: 'Neu', in_kontakt: 'In Kontakt', qualifiziert: 'Qualifiziert',
    kunde: 'Kunde', kein_interesse: 'Kein Interesse',
};
const QUELLE_LABEL = {
    formular: 'Formular', telefon: 'Telefon', messe: 'Messe',
    empfehlung: 'Empfehlung', eigene_recherche: 'Recherche',
    import: 'Import', sonstiges: 'Sonstiges',
};

const kontakte = ref([]);
const laedt = ref(true);
const fehler = ref('');
const suche = ref('');
const filter = ref('alle');
const formOffen = ref(false);
const speichert = ref(false);
const formFehler = ref('');

const leer = () => ({
    firstName: '', lastName: '', email: '', phone: '', position: '',
    status: 'neu', source: 'sonstiges', consentGivenAt: '', consentText: '',
});
const entwurf = ref(leer());

async function laden() {
    laedt.value = true;
    fehler.value = '';
    try {
        const params = {};
        if (suche.value.trim()) params['lastName'] = suche.value.trim();
        if (filter.value !== 'alle') params['status'] = filter.value;
        const { data } = await api.get('/contacts', { params });
        kontakte.value = data['hydra:member'] ?? data.member ?? [];
    } catch (e) {
        fehler.value = 'Die Kontakte konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}

let timer;
watch([suche, filter], () => {
    clearTimeout(timer);
    timer = setTimeout(laden, 250);
});
onMounted(laden);

async function speichern() {
    speichert.value = true;
    formFehler.value = '';
    try {
        const nutzlast = { ...entwurf.value };
        Object.keys(nutzlast).forEach((k) => { if (nutzlast[k] === '') delete nutzlast[k]; });
        if (nutzlast.consentGivenAt) nutzlast.consentGivenAt = new Date(nutzlast.consentGivenAt).toISOString();
        await api.post('/contacts', nutzlast, { headers: { 'Content-Type': 'application/ld+json' } });
        formOffen.value = false;
        entwurf.value = leer();
        await laden();
    } catch (e) {
        formFehler.value = e?.response?.data?.['hydra:description']
            || e?.response?.data?.detail
            || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

const anzahl = computed(() => kontakte.value.length);
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Kontakte</h2>
                <p class="t-subhead">{{ anzahl }} {{ anzahl === 1 ? 'Eintrag' : 'Einträge' }}</p>
            </div>
            <UiButton variant="primary" @click="formOffen = !formOffen">
                <Icon name="plus" :size="16" /> Kontakt anlegen
            </UiButton>
        </header>

        <UiCard v-if="formOffen" class="form">
            <h3 class="t-title-3">Neuer Kontakt</h3>
            <div class="form__grid">
                <UiField v-model="entwurf.firstName" label="Vorname" />
                <UiField v-model="entwurf.lastName" label="Nachname" />
                <UiField v-model="entwurf.email" label="E-Mail" type="email" />
                <UiField v-model="entwurf.phone" label="Telefon" />
                <UiField v-model="entwurf.position" label="Position" />
                <label class="field">
                    <span class="field__label">Herkunft</span>
                    <select v-model="entwurf.source">
                        <option v-for="(l, k) in QUELLE_LABEL" :key="k" :value="k">{{ l }}</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">Status</span>
                    <select v-model="entwurf.status">
                        <option v-for="(l, k) in STATUS_LABEL" :key="k" :value="k">{{ l }}</option>
                    </select>
                </label>
                <UiField v-model="entwurf.consentGivenAt" label="Einwilligung erteilt am" type="date"
                         hint="Leer lassen, wenn keine Einwilligung vorliegt." />
            </div>
            <UiField v-model="entwurf.consentText" label="Wortlaut der Einwilligung"
                     hint="Wird mitgeschrieben, damit später belegbar ist, worin eingewilligt wurde." />
            <p v-if="formFehler" class="t-footnote fehler">{{ formFehler }}</p>
            <div class="row form__actions">
                <UiButton variant="quiet" @click="formOffen = false">Abbrechen</UiButton>
                <UiButton variant="primary" :disabled="speichert" @click="speichern">
                    {{ speichert ? 'Wird gespeichert…' : 'Speichern' }}
                </UiButton>
            </div>
        </UiCard>

        <div class="row toolbar">
            <label class="suche">
                <Icon name="search" :size="17" />
                <input v-model="suche" type="search" placeholder="Nach Nachname suchen" />
            </label>
            <UiSegmented v-model="filter" :options="[
                { value: 'alle', label: 'Alle' },
                { value: 'neu', label: 'Neu' },
                { value: 'qualifiziert', label: 'Qualifiziert' },
                { value: 'kunde', label: 'Kunden' },
            ]" />
        </div>

        <UiCard v-if="fehler"><p class="t-body">{{ fehler }}</p></UiCard>

        <UiCard v-else-if="!laedt && !kontakte.length" class="leer">
            <p class="t-headline">Noch keine Kontakte</p>
            <p class="t-subhead">Lege den ersten Kontakt an — Herkunft und Einwilligung werden gleich mit erfasst.</p>
        </UiCard>

        <div v-else class="tabelle">
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Firma</th><th>Status</th>
                        <th>Herkunft</th><th>Werbung</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="k in kontakte" :key="k.id">
                        <td>
                            <span class="name">{{ k.displayName }}</span>
                            <span class="t-footnote">{{ k.email || k.phone || '—' }}</span>
                        </td>
                        <td class="muted" data-label="Firma">{{ k.company?.name || '—' }}</td>
                        <td data-label="Status"><UiBadge :tone="k.status === 'kunde' ? 'positive' : 'neutral'">{{ STATUS_LABEL[k.status] }}</UiBadge></td>
                        <td class="muted" data-label="Herkunft">{{ QUELLE_LABEL[k.source] }}</td>
                        <td data-label="Werbung">
                            <UiBadge :tone="k.contactable ? 'positive' : 'warn'">
                                {{ k.contactable ? 'Einwilligung liegt vor' : 'Keine Einwilligung' }}
                            </UiBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }

.form { display: flex; flex-direction: column; gap: var(--sp-4); }
.form__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: var(--sp-4); }
.form__actions { justify-content: flex-end; }
.fehler { color: var(--danger); margin: 0; }

.field { display: flex; flex-direction: column; gap: var(--sp-2); }
.field__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }
select {
    font-family: inherit; font-size: var(--text-body);
    color: var(--label-primary); background: var(--bg-input);
    border: 1px solid var(--separator); border-radius: var(--radius-m);
    padding: 11px 14px;
}
select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--accent-quiet); }

.toolbar { flex-wrap: wrap; justify-content: space-between; }
@media (max-width: 700px) {
    .toolbar { flex-direction: column; align-items: stretch; }
    .suche { min-width: 0; min-height: 44px; }
    .toolbar :deep(.seg) { display: grid; grid-template-columns: repeat(4, 1fr); }
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .form__grid { grid-template-columns: 1fr; }
}
.suche {
    display: flex; align-items: center; gap: var(--sp-2);
    background: var(--bg-elevated); border: 1px solid var(--separator);
    border-radius: var(--radius-pill); padding: 8px 14px;
    color: var(--label-tertiary); min-width: 260px;
}
.suche input {
    border: 0; background: transparent; outline: none;
    font-family: inherit; font-size: var(--text-subhead);
    color: var(--label-primary); width: 100%;
}

.tabelle { background: var(--bg-elevated); border: 1px solid var(--separator); border-radius: var(--radius-l); overflow: hidden; }

/* Handy: Tabellen sind auf Daumenbreite unlesbar — jede Zeile wird zur
   Karte, die Spaltenueberschriften verschwinden, die Zellen bekommen ihre
   Bedeutung ueber data-label. */
@media (max-width: 760px) {
    .tabelle { background: transparent; border: 0; border-radius: 0; }
    thead { display: none; }
    tbody tr {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: var(--sp-2) var(--sp-3);
        background: var(--bg-elevated);
        border: 1px solid var(--separator);
        border-radius: var(--radius-m);
        padding: var(--sp-4);
        margin-bottom: var(--sp-2);
    }
    tbody tr:hover { background: var(--bg-elevated); }
    /* Die Trennlinien der Tabelle muessen in der Kartenansicht weg —
       sonst sieht die Karte aus wie eine Tabelle im Kleinen. */
    td, tbody tr td { border-bottom: 0; padding: 0; }
    td:first-child { grid-column: 1 / -1; padding-bottom: var(--sp-1); }
    td[data-label]::before {
        content: attr(data-label) ' ';
        color: var(--label-tertiary);
        font-size: var(--text-caption);
        text-transform: uppercase;
        letter-spacing: .06em;
        display: block;
        margin-bottom: 2px;
    }
}
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left; font-size: var(--text-caption); font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--label-tertiary); padding: var(--sp-3) var(--sp-5);
    border-bottom: 1px solid var(--separator);
}
td { padding: var(--sp-4) var(--sp-5); border-bottom: 1px solid var(--separator); font-size: var(--text-subhead); vertical-align: middle; }
tbody tr:last-child td { border-bottom: 0; }
tbody tr:hover { background: var(--fill-quaternary); }
.name { display: block; font-weight: 600; color: var(--label-primary); }
td .t-footnote { display: block; }

.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }
</style>
