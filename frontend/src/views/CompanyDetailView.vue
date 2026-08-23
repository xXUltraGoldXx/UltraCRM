<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiBadge from '../components/ui/UiBadge.vue';

/**
 * Kundenakte einer Firma: alles, was zu diesem Kunden gehört, auf einer
 * Seite — Personen, Vorgänge, Verlauf, Kennzahlen.
 */
const ART = { anruf: 'Anruf', notiz: 'Notiz', aufgabe: 'Aufgabe', email: 'E-Mail', termin: 'Termin' };
const PHASE = { neu: 'Neu', qualifiziert: 'Qualifiziert', angebot: 'Angebot', verhandlung: 'Verhandlung', gewonnen: 'Gewonnen', verloren: 'Verloren' };

const route = useRoute();
const router = useRouter();
const firma = ref(null);
const kontakte = ref([]);
const vorgaenge = ref([]);
const verlauf = ref([]);
const fehler = ref('');

const geld = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
const datum = new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' });

async function laden() {
    const id = route.params.id;
    const iri = `/api/companies/${id}`;
    try {
        const [f, k, d, a] = await Promise.all([
            api.get(`/companies/${id}`),
            api.get('/contacts', { params: { company: iri } }),
            api.get('/deals', { params: { company: iri } }),
            api.get('/activities', { params: { 'contact.company': iri } }),
        ]);
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

const offen = computed(() => vorgaenge.value.filter((d) => d.open));
const offenerWert = computed(() => geld.format(offen.value.reduce((s, d) => s + Number(d.value || 0), 0)));
const gewonnen = computed(() => vorgaenge.value.filter((d) => d.stage === 'gewonnen'));
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
                        <Icon name="search" :size="17" /> Website
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
                    <p class="t-caption">Ansprechpartner</p>
                    <div v-if="kontakte.length" class="stack tight">
                        <RouterLink v-for="k in kontakte" :key="k.id" :to="`/kontakte/${k.id}`" class="person">
                            <div class="stack tight">
                                <span class="person__name">{{ k.displayName }}</span>
                                <span class="t-footnote muted">{{ k.position || k.email || 'ohne Angabe' }}</span>
                            </div>
                            <span class="spacer" />
                            <UiBadge :tone="k.contactable ? 'positive' : 'warn'">
                                {{ k.contactable ? 'Einwilligung' : 'keine' }}
                            </UiBadge>
                        </RouterLink>
                    </div>
                    <p v-else class="t-footnote muted">Noch keine Person zugeordnet.</p>
                </UiCard>

                <UiCard>
                    <p class="t-caption">Vorgänge</p>
                    <div v-if="vorgaenge.length" class="stack tight">
                        <RouterLink v-for="d in vorgaenge" :key="d.id" to="/pipeline" class="person">
                            <div class="stack tight">
                                <span class="person__name">{{ d.title }}</span>
                                <span class="t-footnote muted">{{ PHASE[d.stage] }}</span>
                            </div>
                            <span class="spacer" />
                            <span class="t-subhead">{{ d.value ? geld.format(Number(d.value)) : '—' }}</span>
                        </RouterLink>
                    </div>
                    <p v-else class="t-footnote muted">Kein Vorgang erfasst.</p>
                </UiCard>
            </div>

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
    </div>
</template>

<style scoped>
.zurueck { align-self: flex-start; }
.drehen { transform: rotate(180deg); }
.fehler { color: var(--danger); }
.tight { gap: 2px; }
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
.person__name { font-size: var(--text-subhead); font-weight: 600; }

.eintrag { border-left: 2px solid var(--separator); padding-left: var(--sp-4); }
.betreff { font-size: var(--text-subhead); font-weight: 600; margin-top: var(--sp-1); }

@media (max-width: 900px) {
    .spalten, .kennzahlen { grid-template-columns: 1fr; }
}
</style>
