<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const auth = useAuthStore();
const benutzer = ref([]);
const katalog = ref([]);
const fehler = ref('');
const hinweis = ref('');
const blattOffen = ref(false);
const speichert = ref(false);
const bearbeiteId = ref(null);

const leer = () => ({
    username: '', displayName: '', email: '',
    plainPassword: '', rolleAdmin: false, permissions: [],
});
const entwurf = ref(leer());

async function laden() {
    try {
        const [u, k] = await Promise.all([
            api.get('/users'),
            api.get('/permissions'),
        ]);
        benutzer.value = u.data['hydra:member'] ?? u.data.member ?? [];
        katalog.value = k.data.gruppen ?? [];
        fehler.value = '';
    } catch (e) {
        fehler.value = e?.response?.status === 403
            ? 'Die Benutzerverwaltung ist Administratoren vorbehalten.'
            : 'Die Benutzer konnten nicht geladen werden.';
    }
}
onMounted(laden);

function neu() {
    bearbeiteId.value = null;
    entwurf.value = leer();
    blattOffen.value = true;
}

function bearbeiten(b) {
    bearbeiteId.value = b.id;
    entwurf.value = {
        username: b.username,
        displayName: b.displayName ?? '',
        email: b.email ?? '',
        plainPassword: '',
        rolleAdmin: (b.roles || []).includes('ROLE_ADMIN'),
        permissions: [...(b.permissions || [])],
    };
    blattOffen.value = true;
}

function umschalten(schluessel) {
    const i = entwurf.value.permissions.indexOf(schluessel);
    if (i >= 0) entwurf.value.permissions.splice(i, 1);
    else entwurf.value.permissions.push(schluessel);
}

async function speichern() {
    speichert.value = true;
    fehler.value = '';
    try {
        const nutzlast = {
            username: entwurf.value.username,
            displayName: entwurf.value.displayName,
            roles: entwurf.value.rolleAdmin ? ['ROLE_ADMIN'] : ['ROLE_USER'],
            permissions: entwurf.value.permissions,
        };
        if (entwurf.value.email) nutzlast.email = entwurf.value.email;
        // Leeres Feld heisst "Passwort unveraendert lassen" — sonst wuerde
        // jedes Speichern der Stammdaten das Passwort loeschen.
        if (entwurf.value.plainPassword) nutzlast.plainPassword = entwurf.value.plainPassword;

        if (bearbeiteId.value) {
            await api.patch(`/users/${bearbeiteId.value}`, nutzlast, {
                headers: { 'Content-Type': 'application/merge-patch+json' },
            });
        } else {
            await api.post('/users', nutzlast, { headers: { 'Content-Type': 'application/ld+json' } });
        }
        blattOffen.value = false;
        hinweis.value = 'Gespeichert.';
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description']
            || e?.response?.data?.detail
            || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

function rechteText(b) {
    if ((b.roles || []).includes('ROLE_SUPERADMIN')) return 'Superadmin — alles';
    if ((b.roles || []).includes('ROLE_ADMIN')) return 'Administrator — alles';
    const n = (b.permissions || []).length;
    return n ? `${n} ${n === 1 ? 'Recht' : 'Rechte'}` : 'keine Rechte';
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Benutzer</h2>
                <p class="t-subhead">Wer darf was — Rechte gelten immer nur im eigenen Mandanten.</p>
            </div>
            <UiButton variant="primary" @click="neu">
                <Icon name="plus" :size="16" /> Benutzer anlegen
            </UiButton>
        </header>

        <p v-if="hinweis" class="t-footnote ok">{{ hinweis }}</p>
        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-for="b in benutzer" :key="b.id" class="zeile">
            <div class="stack tight">
                <span class="name">
                    {{ b.displayName || b.username }}
                    <UiBadge v-if="!b.active" tone="warn">inaktiv</UiBadge>
                </span>
                <span class="t-footnote muted">{{ b.username }} · {{ rechteText(b) }}</span>
            </div>
            <span class="spacer" />
            <UiButton size="sm" @click="bearbeiten(b)">Bearbeiten</UiButton>
        </UiCard>

        <UiSheet :offen="blattOffen"
                 :titel="bearbeiteId ? 'Benutzer bearbeiten' : 'Benutzer anlegen'"
                 bestaetigen="Speichern" :laeuft="speichert"
                 @schliessen="blattOffen = false" @bestaetigen="speichern">
            <UiField v-model="entwurf.username" label="Benutzername" placeholder="z. B. m.schmidt" />
            <UiField v-model="entwurf.displayName" label="Anzeigename" placeholder="Maria Schmidt" />
            <UiField v-model="entwurf.email" label="E-Mail" type="email" />
            <UiField v-model="entwurf.plainPassword" label="Passwort" type="password"
                     :hint="bearbeiteId ? 'Leer lassen, um das bisherige Passwort zu behalten.' : 'Mindestens acht Zeichen.'" />

            <label class="haken">
                <input v-model="entwurf.rolleAdmin" type="checkbox" />
                <span>Administrator — darf alles im Mandanten, einzelne Rechte entfallen</span>
            </label>

            <div v-if="!entwurf.rolleAdmin" class="rechte">
                <div v-for="g in katalog" :key="g.gruppe" class="gruppe">
                    <p class="t-caption">{{ g.gruppe }}</p>
                    <label v-for="r in g.rechte" :key="r.schluessel" class="haken">
                        <input type="checkbox" :checked="entwurf.permissions.includes(r.schluessel)"
                               @change="umschalten(r.schluessel)" />
                        <span>{{ r.text }}</span>
                    </label>
                </div>
            </div>
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.zeile { display: flex; align-items: center; gap: var(--sp-4); }
.zeile p { margin: 0; }
.name { font-size: var(--text-body); font-weight: 600; display: flex; align-items: center; gap: var(--sp-2); }

.haken { display: flex; align-items: flex-start; gap: var(--sp-3); font-size: var(--text-subhead); min-height: 40px; cursor: pointer; }
.haken input { width: 20px; height: 20px; margin-top: 2px; accent-color: var(--accent); flex: none; }

.rechte { display: flex; flex-direction: column; gap: var(--sp-4); }
.gruppe .t-caption { margin: 0 0 var(--sp-1); }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .zeile { flex-direction: column; align-items: stretch; }
    .zeile :deep(.btn) { width: 100%; min-height: 44px; }
}
</style>
