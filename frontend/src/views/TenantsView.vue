<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';

const mandanten = ref([]);
const fehler = ref('');
const formOffen = ref(false);
const speichert = ref(false);
const entwurf = ref({ name: '', slug: '' });

async function laden() {
    try {
        const { data } = await api.get('/tenants');
        mandanten.value = data['hydra:member'] ?? data.member ?? [];
    } catch (e) {
        fehler.value = e?.response?.status === 403
            ? 'Dieser Bereich ist Superadmins vorbehalten.'
            : 'Die Mandanten konnten nicht geladen werden.';
    }
}
onMounted(laden);

// Kennung aus dem Namen ableiten, damit niemand raten muss, was erlaubt ist.
function slugAus(name) {
    return name.toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);
}

async function speichern() {
    speichert.value = true;
    try {
        await api.post('/tenants', {
            name: entwurf.value.name,
            slug: entwurf.value.slug || slugAus(entwurf.value.name),
        }, { headers: { 'Content-Type': 'application/ld+json' } });
        formOffen.value = false;
        entwurf.value = { name: '', slug: '' };
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Mandanten</h2>
                <p class="t-subhead">Jede Firma sieht ausschließlich ihre eigenen Daten.</p>
            </div>
            <UiButton variant="primary" @click="formOffen = !formOffen">
                <Icon name="plus" :size="16" /> Mandant anlegen
            </UiButton>
        </header>

        <UiCard v-if="formOffen" class="form">
            <UiField v-model="entwurf.name" label="Name" placeholder="z. B. Musterbau GmbH" />
            <UiField v-model="entwurf.slug" label="Kennung"
                     :placeholder="entwurf.name ? slugAus(entwurf.name) : 'wird aus dem Namen gebildet'"
                     hint="Nur Kleinbuchstaben, Ziffern und Bindestriche." />
            <div class="row form__actions">
                <UiButton variant="quiet" @click="formOffen = false">Abbrechen</UiButton>
                <UiButton variant="primary" :disabled="speichert || !entwurf.name" @click="speichern">Speichern</UiButton>
            </div>
        </UiCard>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-for="m in mandanten" :key="m.id" class="zeile">
            <div class="stack tight">
                <p class="t-headline">{{ m.name }}</p>
                <p class="t-footnote muted">{{ m.slug }} · seit {{ new Date(m.createdAt).toLocaleDateString('de-DE') }}</p>
            </div>
            <div class="spacer" />
            <UiBadge :tone="m.active ? 'positive' : 'neutral'">{{ m.active ? 'Aktiv' : 'Inaktiv' }}</UiBadge>
        </UiCard>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }
.form { display: flex; flex-direction: column; gap: var(--sp-4); }
.form__actions { justify-content: flex-end; }
.zeile { display: flex; align-items: center; gap: var(--sp-4); }
.tight { gap: 2px; }
.zeile p { margin: 0; }
</style>
