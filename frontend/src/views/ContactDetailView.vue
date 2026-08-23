<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const STATUS = { neu: 'Neu', in_kontakt: 'In Kontakt', qualifiziert: 'Qualifiziert', kunde: 'Kunde', kein_interesse: 'Kein Interesse' };
const QUELLE = { formular: 'Formular', telefon: 'Telefon', messe: 'Messe', empfehlung: 'Empfehlung', eigene_recherche: 'Recherche', import: 'Import', sonstiges: 'Sonstiges' };
const ART = { anruf: 'Anruf', notiz: 'Notiz', aufgabe: 'Aufgabe', email: 'E-Mail', termin: 'Termin' };

const route = useRoute();
const router = useRouter();
const kontakt = ref(null);
const aktivitaeten = ref([]);
const vorgaenge = ref([]);
const fehler = ref('');
const hinweis = ref('');
const laedt = ref(true);

const datum = new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' });
const geld = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });

async function laden() {
    laedt.value = true;
    const id = route.params.id;
    try {
        const iri = `/api/contacts/${id}`;
        const [k, a, d] = await Promise.all([
            api.get(`/contacts/${id}`),
            api.get('/activities', { params: { contact: iri } }),
            api.get('/deals', { params: { contact: iri } }),
        ]);
        kontakt.value = k.data;
        aktivitaeten.value = a.data['hydra:member'] ?? a.data.member ?? [];
        vorgaenge.value = d.data['hydra:member'] ?? d.data.member ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = e?.response?.status === 404
            ? 'Diesen Kontakt gibt es nicht (mehr).'
            : 'Der Kontakt konnte nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);
watch(() => route.params.id, laden);

/* --- Notiz direkt hier anlegen: ein Feld, ein Klick ------------------- */
const notiz = ref('');
const notizArt = ref('notiz');
const notizLaeuft = ref(false);

async function notizSpeichern() {
    if (!notiz.value.trim()) return;
    notizLaeuft.value = true;
    try {
        await api.post('/activities', {
            type: notizArt.value,
            subject: notiz.value.trim().slice(0, 200),
            contact: `/api/contacts/${route.params.id}`,
        }, { headers: { 'Content-Type': 'application/ld+json' } });
        notiz.value = '';
        await laden();
    } catch (e) {
        fehler.value = 'Der Eintrag konnte nicht gespeichert werden.';
    } finally {
        notizLaeuft.value = false;
    }
}

/* --- Bearbeiten im Blatt --------------------------------------------- */
const bearbeiten = ref(false);
const entwurf = ref({});
const speichert = ref(false);

function bearbeitenOeffnen() {
    entwurf.value = {
        firstName: kontakt.value.firstName ?? '',
        lastName: kontakt.value.lastName ?? '',
        email: kontakt.value.email ?? '',
        phone: kontakt.value.phone ?? '',
        position: kontakt.value.position ?? '',
        status: kontakt.value.status,
        notes: kontakt.value.notes ?? '',
    };
    bearbeiten.value = true;
}

async function bearbeitenSpeichern() {
    speichert.value = true;
    try {
        await api.patch(`/contacts/${route.params.id}`, entwurf.value, {
            headers: { 'Content-Type': 'application/merge-patch+json' },
        });
        bearbeiten.value = false;
        hinweis.value = 'Gespeichert.';
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

/* --- Status mit einem Klick weiterschalten --------------------------- */
async function statusSetzen(neu) {
    const vorher = kontakt.value.status;
    kontakt.value.status = neu;
    try {
        await api.patch(`/contacts/${route.params.id}`, { status: neu }, {
            headers: { 'Content-Type': 'application/merge-patch+json' },
        });
    } catch (e) {
        kontakt.value.status = vorher;
        fehler.value = 'Der Status konnte nicht geändert werden.';
    }
}

async function auskunft() {
    try {
        const { data } = await api.get(`/privacy/contacts/${route.params.id}/export`);
        const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `auskunft-${kontakt.value.lastName || route.params.id}.json`;
        a.click();
        URL.revokeObjectURL(url);
        hinweis.value = 'Auskunft erstellt.';
    } catch (e) {
        fehler.value = 'Die Auskunft konnte nicht erstellt werden.';
    }
}

const offeneVorgaenge = computed(() => vorgaenge.value.filter((d) => d.open));
</script>

<template>
    <div class="stack">
        <UiButton variant="quiet" class="zurueck" @click="router.push('/kontakte')">
            <Icon name="chevron" :size="15" class="drehen" /> Alle Kontakte
        </UiButton>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>
        <p v-if="hinweis" class="t-footnote ok">{{ hinweis }}</p>

        <template v-if="kontakt">
            <UiCard class="kopf">
                <div class="kopf__zeile">
                    <div class="stack tight">
                        <h2 class="t-title-1">{{ kontakt.displayName }}</h2>
                        <p class="t-subhead">
                            {{ kontakt.position || 'ohne Funktion' }}
                            <template v-if="kontakt.company">
                                ·
                                <RouterLink :to="`/firmen/${kontakt.company.id}`" class="firmalink">
                                    {{ kontakt.company.name }}
                                </RouterLink>
                            </template>
                        </p>
                    </div>
                    <div class="spacer" />
                    <UiBadge :tone="kontakt.contactable ? 'positive' : 'warn'">
                        {{ kontakt.contactable ? 'Einwilligung liegt vor' : 'Keine Einwilligung' }}
                    </UiBadge>
                </div>

                <div class="kontaktwege">
                    <a v-if="kontakt.email" :href="`mailto:${kontakt.email}`" class="weg">
                        <Icon name="activity" :size="17" /> {{ kontakt.email }}
                    </a>
                    <a v-if="kontakt.phone" :href="`tel:${kontakt.phone}`" class="weg">
                        <Icon name="contacts" :size="17" /> {{ kontakt.phone }}
                    </a>
                    <span v-if="!kontakt.email && !kontakt.phone" class="t-footnote muted">
                        Keine Kontaktdaten hinterlegt.
                    </span>
                </div>

                <div class="aktionen">
                    <UiButton size="sm" variant="primary" @click="bearbeitenOeffnen">Bearbeiten</UiButton>
                    <UiButton size="sm" @click="auskunft">Auskunft</UiButton>
                </div>
            </UiCard>

            <!-- Status: ein Klick, kein Formular -->
            <UiCard>
                <p class="t-caption">Status</p>
                <div class="status">
                    <button v-for="(l, k) in STATUS" :key="k" class="statusknopf"
                            :class="{ aktiv: kontakt.status === k }" @click="statusSetzen(k)">
                        {{ l }}
                    </button>
                </div>
            </UiCard>

            <div class="spalten">
                <UiCard class="verlauf">
                    <p class="t-caption">Verlauf</p>

                    <div class="neu">
                        <select v-model="notizArt" class="art">
                            <option v-for="(l, k) in ART" :key="k" :value="k">{{ l }}</option>
                        </select>
                        <input v-model="notiz" class="notizfeld" placeholder="Was ist passiert?"
                               @keyup.enter="notizSpeichern" />
                        <UiButton size="sm" variant="primary" :disabled="notizLaeuft || !notiz.trim()"
                                  @click="notizSpeichern">
                            <Icon name="plus" :size="15" />
                        </UiButton>
                    </div>

                    <div v-if="aktivitaeten.length" class="eintraege">
                        <article v-for="a in aktivitaeten" :key="a.id" class="eintrag">
                            <div class="row">
                                <UiBadge tone="quiet">{{ ART[a.type] }}</UiBadge>
                                <UiBadge v-if="a.overdue" tone="warn">Überfällig</UiBadge>
                                <span class="spacer" />
                                <span class="t-footnote muted">{{ datum.format(new Date(a.createdAt)) }}</span>
                            </div>
                            <p class="betreff">{{ a.subject }}</p>
                            <p v-if="a.body" class="t-footnote">{{ a.body }}</p>
                        </article>
                    </div>
                    <p v-else class="t-footnote muted">Noch nichts passiert.</p>
                </UiCard>

                <div class="stack seite">
                    <UiCard>
                        <p class="t-caption">Vorgänge</p>
                        <div v-if="vorgaenge.length" class="stack tight">
                            <RouterLink v-for="d in vorgaenge" :key="d.id" to="/pipeline" class="vorgang">
                                <span class="vorgang__titel">{{ d.title }}</span>
                                <span class="t-footnote muted">
                                    {{ d.value ? geld.format(Number(d.value)) : 'ohne Wert' }} · {{ d.stage }}
                                </span>
                            </RouterLink>
                            <p class="t-footnote muted">{{ offeneVorgaenge.length }} davon offen</p>
                        </div>
                        <p v-else class="t-footnote muted">Kein Vorgang verknüpft.</p>
                    </UiCard>

                    <UiCard>
                        <p class="t-caption">Datenschutz</p>
                        <dl>
                            <dt class="t-footnote">Herkunft</dt>
                            <dd>{{ QUELLE[kontakt.source] }}</dd>
                            <dt class="t-footnote">Einwilligung</dt>
                            <dd v-if="kontakt.consentGivenAt">
                                {{ datum.format(new Date(kontakt.consentGivenAt)) }}
                                <span v-if="kontakt.consentWithdrawnAt" class="widerrufen">
                                    · widerrufen am {{ datum.format(new Date(kontakt.consentWithdrawnAt)) }}
                                </span>
                            </dd>
                            <dd v-else class="muted">nicht erteilt</dd>
                            <template v-if="kontakt.consentText">
                                <dt class="t-footnote">Wortlaut</dt>
                                <dd class="wortlaut">{{ kontakt.consentText }}</dd>
                            </template>
                            <template v-if="kontakt.deleteAfter">
                                <dt class="t-footnote">Löschung vorgemerkt</dt>
                                <dd>{{ kontakt.deleteAfter }}</dd>
                            </template>
                        </dl>
                    </UiCard>
                </div>
            </div>
        </template>

        <UiSheet :offen="bearbeiten" titel="Kontakt bearbeiten" bestaetigen="Speichern"
                 :laeuft="speichert" @schliessen="bearbeiten = false" @bestaetigen="bearbeitenSpeichern">
            <UiField v-model="entwurf.firstName" label="Vorname" />
            <UiField v-model="entwurf.lastName" label="Nachname" />
            <UiField v-model="entwurf.email" label="E-Mail" type="email" />
            <UiField v-model="entwurf.phone" label="Telefon" />
            <UiField v-model="entwurf.position" label="Position" />
            <UiField v-model="entwurf.notes" label="Notizen" />
        </UiSheet>
    </div>
</template>

<style scoped>
.zurueck { align-self: flex-start; }
.drehen { transform: rotate(180deg); }
.fehler { color: var(--danger); }
.ok { color: var(--success); }
.tight { gap: 2px; }
p { margin: 0; }
.t-caption { margin: 0 0 var(--sp-3); }

.kopf { display: flex; flex-direction: column; gap: var(--sp-4); }
.kopf__zeile { display: flex; align-items: flex-start; gap: var(--sp-3); flex-wrap: wrap; }
.kontaktwege { display: flex; gap: var(--sp-4); flex-wrap: wrap; }
.weg {
    display: inline-flex; align-items: center; gap: var(--sp-2);
    color: var(--accent); font-size: var(--text-subhead); text-decoration: none;
    min-height: 40px;
}
.aktionen { display: flex; gap: var(--sp-2); flex-wrap: wrap; }
.firmalink { font-weight: 600; }

.status { display: flex; gap: var(--sp-2); flex-wrap: wrap; }
.statusknopf {
    border: 1px solid var(--separator);
    background: transparent;
    color: var(--label-secondary);
    font-family: inherit; font-size: var(--text-footnote); font-weight: 500;
    padding: 8px 14px; min-height: 40px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    transition: background-color .16s ease, color .16s ease, border-color .16s ease;
}
.statusknopf:hover { background: var(--fill-quaternary); color: var(--label-primary); }
.statusknopf.aktiv { background: var(--accent); border-color: var(--accent); color: #fff; }

.spalten { display: grid; grid-template-columns: 1.4fr 1fr; gap: var(--sp-4); align-items: start; }
.seite { gap: var(--sp-4); }

.neu { display: flex; gap: var(--sp-2); margin-bottom: var(--sp-4); }
.art, .notizfeld {
    font-family: inherit; font-size: var(--text-subhead);
    background: var(--bg-input); color: var(--label-primary);
    border: 1px solid var(--separator); border-radius: var(--radius-m);
    padding: 10px 12px; min-height: 42px;
}
.notizfeld { flex: 1; min-width: 0; }
.notizfeld:focus, .art:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--accent-quiet); }
.neu :deep(.btn) { min-height: 42px; }

.eintraege { display: flex; flex-direction: column; gap: var(--sp-3); }
.eintrag { border-left: 2px solid var(--separator); padding-left: var(--sp-4); }
.betreff { font-size: var(--text-subhead); font-weight: 600; margin-top: var(--sp-1); }

.vorgang {
    display: flex; flex-direction: column; gap: 2px;
    padding: var(--sp-3); border: 1px solid var(--separator);
    border-radius: var(--radius-m); text-decoration: none; color: inherit;
}
.vorgang:hover { border-color: var(--accent); text-decoration: none; }
.vorgang__titel { font-size: var(--text-subhead); font-weight: 600; }

dl { margin: 0; display: grid; gap: var(--sp-1); }
dt { color: var(--label-tertiary); }
dd { margin: 0 0 var(--sp-2); font-size: var(--text-subhead); }
.wortlaut { font-size: var(--text-footnote); color: var(--label-secondary); }
.widerrufen { color: var(--warning); }

@media (max-width: 900px) {
    .spalten { grid-template-columns: 1fr; }
    .aktionen :deep(.btn) { flex: 1; min-height: 44px; }
    .kontaktwege { flex-direction: column; gap: var(--sp-2); }
}
</style>
