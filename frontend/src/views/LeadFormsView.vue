<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';

const formulare = ref([]);
const fehler = ref('');
const formOffen = ref(false);
const speichert = ref(false);
const entwurf = ref({ name: '', consentText: 'Ich bin damit einverstanden, zu meiner Anfrage kontaktiert zu werden.' });
const kopiert = ref(null);

async function laden() {
    try {
        const { data } = await api.get('/lead_forms');
        formulare.value = data['hydra:member'] ?? data.member ?? [];
    } catch (e) {
        fehler.value = 'Die Formulare konnten nicht geladen werden.';
    }
}
onMounted(laden);

async function speichern() {
    speichert.value = true;
    try {
        await api.post('/lead_forms', entwurf.value, { headers: { 'Content-Type': 'application/ld+json' } });
        formOffen.value = false;
        entwurf.value = { name: '', consentText: entwurf.value.consentText };
        await laden();
    } catch (e) {
        fehler.value = e?.response?.data?.['hydra:description'] || 'Speichern hat nicht geklappt.';
    } finally {
        speichert.value = false;
    }
}

/* Fertiges HTML zum Einbetten. Bewusst ohne Framework und ohne externe
   Skripte — das laeuft in jeder Website, auch in einer alten. */
function schnipsel(f) {
    return `<form id="ug-lead" style="max-width:420px;display:grid;gap:12px">
  <input type="hidden" name="token" value="${f.token}">
  <input name="firstName" placeholder="Vorname">
  <input name="lastName" placeholder="Nachname" required>
  <input name="email" type="email" placeholder="E-Mail">
  <input name="phone" placeholder="Telefon">
  <textarea name="message" placeholder="Ihre Nachricht" rows="4"></textarea>
  <!-- Falle für Bots: für Menschen unsichtbar, muss leer bleiben -->
  <input name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off">
  <label><input type="checkbox" name="consent" required> ${f.consentText}</label>
  <button type="submit">Absenden</button>
</form>
<script>
document.getElementById('ug-lead').addEventListener('submit', async (e) => {
  e.preventDefault();
  const d = Object.fromEntries(new FormData(e.target));
  d.consent = e.target.consent.checked;
  const r = await fetch('https://crm.ultragold.de/api/public/leads', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d),
  });
  e.target.innerHTML = r.ok
    ? '<p>Vielen Dank, wir melden uns.</p>'
    : '<p>Das hat leider nicht geklappt. Bitte später erneut versuchen.</p>';
});
<\/script>`;
}

async function kopieren(f) {
    try {
        await navigator.clipboard.writeText(schnipsel(f));
        kopiert.value = f.id;
        setTimeout(() => { kopiert.value = null; }, 2000);
    } catch (e) {
        fehler.value = 'Kopieren nicht möglich — bitte den Text von Hand markieren.';
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Lead-Formulare</h2>
                <p class="t-subhead">Einbetten, ausfüllen lassen, Kontakte landen direkt hier.</p>
            </div>
            <UiButton variant="primary" @click="formOffen = !formOffen">
                <Icon name="plus" :size="16" /> Formular anlegen
            </UiButton>
        </header>

        <UiCard v-if="formOffen" class="form">
            <UiField v-model="entwurf.name" label="Name" placeholder="z. B. Kontaktformular Website" />
            <UiField v-model="entwurf.consentText" label="Einwilligungstext"
                     hint="Genau dieser Wortlaut wird bei jedem Lead mitgespeichert." />
            <div class="row form__actions">
                <UiButton variant="quiet" @click="formOffen = false">Abbrechen</UiButton>
                <UiButton variant="primary" :disabled="speichert || !entwurf.name" @click="speichern">Speichern</UiButton>
            </div>
        </UiCard>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!formulare.length" class="leer">
            <p class="t-headline">Noch kein Formular</p>
            <p class="t-subhead">Lege eines an und binde es auf deiner Website ein.</p>
        </UiCard>

        <UiCard v-for="f in formulare" :key="f.id" class="eintrag">
            <div class="row">
                <div class="stack tight">
                    <p class="t-headline">{{ f.name }}</p>
                    <p class="t-footnote muted">Token {{ f.token.slice(0, 10) }}… · angelegt {{ new Date(f.createdAt).toLocaleDateString('de-DE') }}</p>
                </div>
                <div class="spacer" />
                <UiBadge :tone="f.active ? 'positive' : 'neutral'">{{ f.active ? 'Aktiv' : 'Inaktiv' }}</UiBadge>
                <UiButton size="sm" @click="kopieren(f)">
                    <Icon :name="kopiert === f.id ? 'check' : 'plus'" :size="15" />
                    {{ kopiert === f.id ? 'Kopiert' : 'HTML kopieren' }}
                </UiButton>
            </div>
            <details>
                <summary class="t-footnote">Code ansehen</summary>
                <pre>{{ schnipsel(f) }}</pre>
            </details>
        </UiCard>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.fehler { color: var(--danger); }
.form { display: flex; flex-direction: column; gap: var(--sp-4); }
.form__actions { justify-content: flex-end; }
.tight { gap: 2px; }
.eintrag { display: flex; flex-direction: column; gap: var(--sp-3); }
.eintrag p { margin: 0; }
summary { cursor: pointer; color: var(--accent); }
pre {
    margin: var(--sp-3) 0 0;
    padding: var(--sp-4);
    background: var(--bg-grouped);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    font-family: var(--font-mono);
    font-size: var(--text-caption);
    line-height: 1.55;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .eintrag .row { flex-wrap: wrap; }
    .eintrag :deep(.btn) { min-height: 44px; }
}
</style>
