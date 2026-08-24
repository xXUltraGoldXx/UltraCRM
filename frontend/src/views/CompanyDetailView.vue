<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiField from '../components/ui/UiField.vue';
import UiSheet from '../components/ui/UiSheet.vue';
import AenderungsProtokoll from '../components/AenderungsProtokoll.vue';
import { ART } from '../labels.js';
import { datum, geld } from '../format.js';

/**
 * Kundenakte einer Firma: alles, was zu diesem Kunden gehört, auf einer
 * Seite — Personen, Vorgänge, Verlauf, Kennzahlen.
 */

const route = useRoute();
const router = useRouter();
const firma = ref(null);
const kontakte = ref([]);
const vorgaenge = ref([]);
const verlauf = ref([]);
const zusatzfelder = ref([]);
const fehler = ref('');

async function laden() {
    const id = route.params.id;
    const iri = `/api/companies/${id}`;
    try {
        const [f, k, d, a, z] = await Promise.all([
            api.get(`/companies/${id}`),
            api.get('/contacts', { params: { company: iri } }),
            api.get('/deals', { params: { company: iri } }),
            api.get('/activities', { params: { 'contact.company': iri } }),
            api.get('/custom_field_definitions', { params: { entityType: 'company' } }),
        ]);
        zusatzfelder.value = (z.data['hydra:member'] ?? z.data.member ?? [])
            .filter((x) => x.entityType === 'company');
        firma.value = f.data;
        kontakte.value = k.data['hydra:member'] ?? k.data.member ?? [];
        vorgaenge.value = d.data['hydra:member'] ?? d.data.member ?? [];
        verlauf.value = a.data['hydra:member'] ?? a.data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = e?.response?.status === 404
            ? 'Diese Firma gibt es nicht (mehr).'
            : 'Die Firma konnte nicht geladen werden.';
    }
}
onMounted(laden);
watch(() => route.params.id, laden);

/* Personen nach Abteilung gruppieren; Hauptansprechpartner steht oben.
   So sieht man auf einen Blick, wer wofür zuständig ist. */
const nachAbteilung = computed(() => {
    const gruppen = new Map();
    [...kontakte.value]
        .sort((a, b) => (b.primaryContact === true) - (a.primaryContact === true)
            || (a.lastName || '').localeCompare(b.lastName || ''))
        .forEach((k) => {
            const schl = k.department?.trim() || 'Ohne Abteilung';
            if (!gruppen.has(schl)) gruppen.set(schl, []);
            gruppen.get(schl).push(k);
        });
    // "Ohne Abteilung" ans Ende — benannte Abteilungen sind aussagekräftiger.
    return [...gruppen.entries()].sort((a, b) =>
        (a[0] === 'Ohne Abteilung') - (b[0] === 'Ohne Abteilung') || a[0].localeCompare(b[0]));
});

const personAnlegen = ref(false);
const personSpeichert = ref(false);
const leerePerson = () => ({
    firstName: '', lastName: '', position: '', department: '',
    email: '', phone: '', primaryContact: false, source: 'sonstiges',
});
const person = ref(leerePerson());

async function personSpeichern() {
    personSpeichert.value = true;
    try {
        const n = Object.fromEntries(Object.entries(person.value).filter(([, v]) => v !== '' && v !== false));
        n.company = `/api/companies/${route.params.id}`;
        n.source = person.value.source;
        await api.post('/contacts', n, { headers: { 'Content-Type': 'application/ld+json' } });
        personAnlegen.value = false;
        person.value = leerePerson();
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description'] || 'Die Person konnte nicht angelegt werden.';
    } finally {
        personSpeichert.value = false;
    }
}

/* Hauptansprechpartner umsetzen: der bisherige verliert die Markierung,
   damit es immer genau einen gibt. */
async function alsHauptansprechpartner(k) {
    // Ein einziger Aufruf: der Server setzt den bisherigen Hauptkontakt in
    // derselben Transaktion zurueck. Frueher liefen dafuer mehrere PATCHes
    // parallel — schlug einer fehl, blieb ein zerrissener Stand zurueck.
    try {
        await api.patch(`/contacts/${k.id}`, { primaryContact: true },
            { headers: { 'Content-Type': 'application/merge-patch+json' } });
    } catch (e) {
        fehler.value = 'Die Änderung hat nicht geklappt.';
    } finally {
        // Immer neu laden — auch im Fehlerfall, damit der echte Serverstand
        // sichtbar wird statt eines geratenen.
        await laden();
    }
}

const offen = computed(() => vorgaenge.value.filter((d) => d.open));
const offenerWert = computed(() => geld.format(offen.value.reduce((s, d) => s + Number(d.value || 0), 0)));
// Nicht der Name der Phase entscheidet, sondern ihre Art (siehe A5).
const gewonnen = computed(() => vorgaenge.value.filter((d) => d.stage?.art === 'gewonnen'));
const gewonnenerWert = computed(() => geld.format(gewonnen.value.reduce((s, d) => s + Number(d.value || 0), 0)));
const mitEinwilligung = computed(() => kontakte.value.filter((k) => k.contactable).length);
</script>

<template>
    <div class="stack">
        <UiButton variant="quiet" class="zurueck" @click="router.push('/kontakte')">
            <Icon name="chevron" :size="15" class="drehen" /> Zurück
        </UiButton>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <template v-if="firma">
            <UiCard class="kopf">
                <div class="kopf__zeile">
                    <div class="stack tight">
                        <h2 class="t-title-1">{{ firma.name }}</h2>
                        <p class="t-subhead">
                            <template v-if="firma.street || firma.city">
                                {{ [firma.street, [firma.zipCode, firma.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') }}
                            </template>
                            <template v-else>Keine Anschrift hinterlegt</template>
                        </p>
                    </div>
                    <div class="spacer" />
                    <a v-if="firma.website" :href="firma.website" target="_blank" rel="noopener" class="weg">
                        <Icon name="globe" :size="17" /> Website
                    </a>
                </div>
            </UiCard>

            <div class="kennzahlen">
                <UiCard>
                    <p class="t-caption">Personen</p>
                    <p class="zahl">{{ kontakte.length }}</p>
                    <p class="t-footnote">{{ mitEinwilligung }} mit Einwilligung</p>
                </UiCard>
                <UiCard>
                    <p class="t-caption">Offen</p>
                    <p class="zahl">{{ offenerWert }}</p>
                    <p class="t-footnote">{{ offen.length }} Vorgänge</p>
                </UiCard>
                <UiCard>
                    <p class="t-caption">Gewonnen</p>
                    <p class="zahl">{{ gewonnenerWert }}</p>
                    <p class="t-footnote">{{ gewonnen.length }} Abschlüsse</p>
                </UiCard>
            </div>

            <div class="spalten">
                <UiCard>
                    <div class="row kopfzeile">
                        <p class="t-caption">Personen</p>
                        <span class="spacer" />
                        <UiButton size="sm" @click="personAnlegen = true">
                            <Icon name="plus" :size="15" /> Person
                        </UiButton>
                    </div>

                    <div v-if="kontakte.length" class="stack">
                        <div v-for="[abteilung, leute] in nachAbteilung" :key="abteilung" class="stack tight">
                            <p class="abteilung t-footnote">{{ abteilung }}</p>
                            <div v-for="k in leute" :key="k.id" class="person">
                                <RouterLink :to="`/kontakte/${k.id}`" class="person__text">
                                    <span class="person__name">
                                        {{ k.displayName }}
                                        <UiBadge v-if="k.primaryContact" tone="quiet">Hauptkontakt</UiBadge>
                                    </span>
                                    <span class="t-footnote muted">
                                        {{ k.position || 'ohne Funktion' }}
                                        <template v-if="k.email"> · {{ k.email }}</template>
                                    </span>
                                </RouterLink>
                                <span class="spacer" />
                                <button v-if="!k.primaryContact" class="markieren t-footnote"
                                        @click="alsHauptansprechpartner(k)">
                                    Zum Hauptkontakt machen
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="t-footnote muted">Noch keine Person zugeordnet.</p>
                </UiCard>

                <UiCard>
                    <p class="t-caption">Vorgänge</p>
                    <div v-if="vorgaenge.length" class="stack tight">
                        <RouterLink v-for="d in vorgaenge" :key="d.id" to="/pipeline" class="person">
                            <div class="stack tight">
                                <span class="person__name">{{ d.title }}</span>
                                <span class="t-footnote muted">{{ d.stageName }}</span>
                            </div>
                            <span class="spacer" />
                            <span class="t-subhead">{{ d.value ? geld.format(Number(d.value)) : '—' }}</span>
                        </RouterLink>
                    </div>
                    <p v-else class="t-footnote muted">Kein Vorgang erfasst.</p>
                </UiCard>
            </div>

            <UiCard v-if="zusatzfelder.length">
                <p class="t-caption">Weitere Angaben</p>
                <dl class="zusatz">
                    <template v-for="f in zusatzfelder" :key="f.id">
                        <dt class="t-footnote">{{ f.label }}</dt>
                        <dd>
                            <template v-if="firma.customData?.[f.fieldKey] !== undefined && firma.customData?.[f.fieldKey] !== null">
                                <template v-if="f.type === 'janein'">{{ firma.customData[f.fieldKey] ? 'Ja' : 'Nein' }}</template>
                                <template v-else>{{ firma.customData[f.fieldKey] }}</template>
                            </template>
                            <span v-else class="muted">—</span>
                        </dd>
                    </template>
                </dl>
            </UiCard>

            <AenderungsProtokoll subjectType="company" :subjectId="firma.id" />

            <UiCard>
                <p class="t-caption">Verlauf des gesamten Kunden</p>
                <div v-if="verlauf.length" class="stack">
                    <article v-for="a in verlauf" :key="a.id" class="eintrag">
                        <div class="row">
                            <UiBadge tone="quiet">{{ ART[a.type] }}</UiBadge>
                            <span class="spacer" />
                            <span class="t-footnote muted">{{ datum.format(new Date(a.createdAt)) }}</span>
                        </div>
                        <p class="betreff">{{ a.subject }}</p>
                        <p v-if="a.body" class="t-footnote">{{ a.body }}</p>
                    </article>
                </div>
                <p v-else class="t-footnote muted">Noch nichts passiert.</p>
            </UiCard>
        </template>

        <UiSheet :offen="personAnlegen" :titel="`Person zu ${firma?.name ?? ''} hinzufügen`"
                 bestaetigen="Anlegen" :laeuft="personSpeichert"
                 @schliessen="personAnlegen = false" @bestaetigen="personSpeichern">
            <div class="zeile">
                <UiField v-model="person.firstName" label="Vorname" placeholder="Frank" />
                <UiField v-model="person.lastName" label="Nachname" placeholder="Müller" />
            </div>
            <div class="zeile">
                <UiField v-model="person.position" label="Funktion" placeholder="z. B. CEO, Vertriebsleiter" />
                <UiField v-model="person.department" label="Abteilung" placeholder="z. B. Vertrieb" />
            </div>
            <UiField v-model="person.email" label="E-Mail" type="email" />
            <UiField v-model="person.phone" label="Telefon" />
            <label class="haken">
                <input v-model="person.primaryContact" type="checkbox" />
                <span>Hauptansprechpartner dieser Firma</span>
            </label>
        </UiSheet>
    </div>
</template>

<style scoped>
p { margin: 0; }
.t-caption { margin: 0 0 var(--sp-3); }

.kopf__zeile { display: flex; align-items: flex-start; gap: var(--sp-3); flex-wrap: wrap; }
.weg { display: inline-flex; align-items: center; gap: var(--sp-2); color: var(--accent); font-size: var(--text-subhead); min-height: 40px; text-decoration: none; }

.kennzahlen { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: var(--sp-4); }
.zahl { font-size: var(--text-title-1); font-weight: 700; margin: 0 0 2px; font-variant-numeric: tabular-nums; }

.spalten { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); align-items: start; }

.person {
    display: flex; align-items: center; gap: var(--sp-3);
    padding: var(--sp-3); border: 1px solid var(--separator);
    border-radius: var(--radius-m); text-decoration: none; color: inherit;
    min-height: 52px;
}
.person:hover { border-color: var(--accent); text-decoration: none; }
.person__name { font-size: var(--text-subhead); font-weight: 600; display: flex; align-items: center; gap: var(--sp-2); }
.person__text { display: flex; flex-direction: column; gap: 2px; text-decoration: none; color: inherit; min-width: 0; }
.person__text:hover .person__name { color: var(--accent); }
.kopfzeile { margin-bottom: var(--sp-3); }
.kopfzeile .t-caption { margin: 0; }
.abteilung {
    text-transform: uppercase; letter-spacing: .06em; font-weight: 600;
    color: var(--label-tertiary); margin: var(--sp-3) 0 var(--sp-1);
}
.markieren {
    border: 1px solid var(--separator); background: transparent;
    color: var(--label-secondary); border-radius: var(--radius-pill);
    padding: 5px 11px; cursor: pointer; min-height: 34px; font-family: inherit;
}
.markieren:hover { border-color: var(--accent); color: var(--accent); }
.zeile { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.haken { display: flex; align-items: center; gap: var(--sp-3); font-size: var(--text-subhead); min-height: 44px; }
.haken input { width: 20px; height: 20px; accent-color: var(--accent); }

.eintrag { border-left: 2px solid var(--separator); padding-left: var(--sp-4); }
.zusatz { margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--sp-3); }
.zusatz dt { color: var(--label-tertiary); }
.zusatz dd { margin: 2px 0 0; font-size: var(--text-subhead); }
.betreff { font-size: var(--text-subhead); font-weight: 600; margin-top: var(--sp-1); }

@media (max-width: 900px) {
    .spalten, .kennzahlen { grid-template-columns: 1fr; }
}
</style>
