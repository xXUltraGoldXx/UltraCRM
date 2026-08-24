<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSegmented from '../components/ui/UiSegmented.vue';

const PROVIDER_OPTIONEN = [
    { value: 'smtp', label: 'SMTP' },
    { value: 'mailjet', label: 'Mailjet' },
];

const leer = () => ({
    provider: 'smtp',
    host: '',
    port: 587,
    username: '',
    fromAddress: '',
    fromName: '',
    active: true,
});

const id = ref(null);
const entwurf = ref(leer());
const secretSet = ref(false);
const secretErsetzen = ref(false);
const plainSecret = ref('');

const laedt = ref(true);
const speichert = ref(false);
const fehler = ref('');
const hinweis = ref('');

async function laden() {
    laedt.value = true;
    fehler.value = '';
    hinweis.value = '';
    try {
        const { data } = await api.get('/mail_settings');
        const eintrag = (data['hydra:member'] ?? data.member ?? [])[0] ?? null;
        if (eintrag) {
            id.value = eintrag.id;
            entwurf.value = {
                provider: eintrag.provider,
                host: eintrag.host || '',
                port: eintrag.port ?? 587,
                username: eintrag.username || '',
                fromAddress: eintrag.fromAddress || '',
                fromName: eintrag.fromName || '',
                active: eintrag.active,
            };
            secretSet.value = !!eintrag.secretSet;
            // With no secret stored yet, the field starts out open for entry.
            secretErsetzen.value = !secretSet.value;
        } else {
            id.value = null;
            entwurf.value = leer();
            secretSet.value = false;
            secretErsetzen.value = true;
        }
        plainSecret.value = '';
    } catch (e) {
        fehler.value = 'Die Versandeinstellungen konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

// Mailjet is accessed via their SMTP gateway (in-v3.mailjet.com:587, API
// key as the username, secret as the password — see api/src/Entity/MailSetting.php).
// Only prefill these values on an actual selection by the user, not when
// reloading an existing entry.
function providerWaehlen(wert) {
    entwurf.value.provider = wert;
    if (wert === 'mailjet') {
        if (!entwurf.value.host) entwurf.value.host = 'in-v3.mailjet.com';
        if (!entwurf.value.port) entwurf.value.port = 587;
    }
}

function secretErsetzenStarten() {
    secretErsetzen.value = true;
    plainSecret.value = '';
}
function secretErsetzenAbbrechen() {
    secretErsetzen.value = false;
    plainSecret.value = '';
}

async function speichern() {
    speichert.value = true;
    fehler.value = '';
    hinweis.value = '';
    try {
        const nutzlast = {
            provider: entwurf.value.provider,
            host: entwurf.value.host || null,
            port: entwurf.value.port ? Number(entwurf.value.port) : null,
            username: entwurf.value.username || null,
            fromAddress: entwurf.value.fromAddress,
            fromName: entwurf.value.fromName,
            active: entwurf.value.active,
        };
        // Don't send an empty field — otherwise the processor deletes the
        // existing secret (see api/src/State/MailSettingProcessor.php).
        if (secretErsetzen.value && plainSecret.value.trim() !== '') {
            nutzlast.plainSecret = plainSecret.value;
        }

        if (id.value) {
            await api.patch(`/mail_settings/${id.value}`, nutzlast, {
                headers: { 'Content-Type': 'application/merge-patch+json' },
            });
        } else {
            await api.post('/mail_settings', nutzlast, {
                headers: { 'Content-Type': 'application/ld+json' },
            });
        }
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

/* --- Test email ---------------------------------------------------------- */
const testAdresse = ref('');
const testLaeuft = ref(false);
const testErgebnis = ref(null); // { ok: bool, text: string }

async function testSenden() {
    if (!testAdresse.value.trim()) return;
    testLaeuft.value = true;
    testErgebnis.value = null;
    try {
        const { data } = await api.post('/mail/test', { to: testAdresse.value.trim() });
        testErgebnis.value = { ok: true, text: `Testmail an ${data.an ?? testAdresse.value} gesendet.` };
    } catch (e) {
        testErgebnis.value = {
            ok: false,
            text: e?.response?.data?.error
                || e?.response?.data?.detail
                || e?.response?.data?.['hydra:description']
                || 'Die Testmail konnte nicht gesendet werden.',
        };
    } finally {
        testLaeuft.value = false;
    }
}
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Versand</h2>
                <p class="t-subhead">Über welchen Weg das CRM E-Mails verschickt.</p>
            </div>
        </header>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>
        <p v-if="hinweis" class="t-footnote erfolg">{{ hinweis }}</p>

        <p v-if="laedt" class="t-footnote muted">Lädt…</p>

        <UiCard v-else class="form">
            <label class="feld">
                <span class="feld__label">Versandweg</span>
                <UiSegmented :model-value="entwurf.provider" :options="PROVIDER_OPTIONEN"
                             @update:model-value="providerWaehlen" />
            </label>

            <UiCard v-if="entwurf.provider === 'mailjet'" class="hinweis">
                <p class="t-footnote muted">
                    Mailjet läuft über deren SMTP-Zugang: Server und Port sind schon vorbelegt.
                    Als Benutzername den API-Key eintragen, als Passwort das zugehörige Secret.
                </p>
            </UiCard>

            <UiField v-model="entwurf.host" label="Server" placeholder="z. B. smtp.example.de" />
            <UiField v-model="entwurf.port" label="Port" type="number" placeholder="587" />
            <UiField v-model="entwurf.username" :label="entwurf.provider === 'mailjet' ? 'API-Key' : 'Benutzername'" />

            <div class="feld">
                <span class="feld__label">{{ entwurf.provider === 'mailjet' ? 'API-Secret' : 'Passwort' }}</span>
                <template v-if="secretErsetzen">
                    <input v-model="plainSecret" type="password" autocomplete="new-password"
                           :placeholder="entwurf.provider === 'mailjet' ? 'API-Secret eingeben' : 'Passwort eingeben'" />
                    <span class="t-footnote muted">
                        Leer lassen und speichern behält das hinterlegte
                        {{ entwurf.provider === 'mailjet' ? 'Secret' : 'Passwort' }} bei.
                    </span>
                    <UiButton v-if="secretSet" variant="quiet" size="sm" type="button" @click="secretErsetzenAbbrechen">
                        Abbrechen
                    </UiButton>
                </template>
                <div v-else class="row">
                    <UiBadge tone="positive">Hinterlegt</UiBadge>
                    <UiButton variant="quiet" size="sm" type="button" @click="secretErsetzenStarten">Ersetzen</UiButton>
                </div>
            </div>

            <UiField v-model="entwurf.fromAddress" label="Absenderadresse" type="email" placeholder="versand@example.de" />
            <UiField v-model="entwurf.fromName" label="Absendername" placeholder="z. B. UltraCRM" />

            <label class="haken">
                <input v-model="entwurf.active" type="checkbox" />
                <span>Versand aktiv</span>
            </label>

            <div class="row form__actions">
                <UiButton variant="primary" :disabled="speichert" @click="speichern">
                    {{ speichert ? 'Speichert…' : 'Speichern' }}
                </UiButton>
            </div>
        </UiCard>

        <UiCard class="test">
            <p class="t-headline">Testmail</p>
            <p class="t-footnote muted">Prüft, ob der hinterlegte Versandweg funktioniert.</p>
            <div class="test__zeile">
                <UiField v-model="testAdresse" type="email" placeholder="empfaenger@example.de" />
                <UiButton variant="secondary" :disabled="testLaeuft || !testAdresse" @click="testSenden">
                    {{ testLaeuft ? 'Sendet…' : 'Testmail senden' }}
                </UiButton>
            </div>
            <p v-if="testErgebnis" class="t-footnote" :class="testErgebnis.ok ? 'erfolg' : 'fehler'">
                {{ testErgebnis.text }}
            </p>
        </UiCard>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.erfolg { color: var(--success); }

.form { display: flex; flex-direction: column; gap: var(--sp-4); }
.form__actions { justify-content: flex-end; }

.hinweis { background: var(--accent-quiet); border-color: transparent; padding: var(--sp-4); }
.hinweis p { margin: 0; }

.feld { display: flex; flex-direction: column; gap: var(--sp-2); }
.feld__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }
.feld input[type="password"] {
    font-family: inherit; font-size: var(--text-body); color: var(--label-primary);
    background: var(--bg-input); border: 1px solid var(--separator);
    border-radius: var(--radius-m); padding: 11px 14px; min-height: 44px;
}
.feld input[type="password"]:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--accent-quiet);
}

.haken { display: flex; align-items: center; gap: var(--sp-3); font-size: var(--text-subhead); min-height: 44px; }
.haken input { width: 20px; height: 20px; accent-color: var(--accent); }

.test { display: flex; flex-direction: column; gap: var(--sp-3); }
.test p { margin: 0; }
.test__zeile { display: flex; gap: var(--sp-3); align-items: flex-end; flex-wrap: wrap; }
.test__zeile :deep(.field) { flex: 1; min-width: 220px; }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .form__actions :deep(.btn) { width: 100%; min-height: 46px; }
    .test__zeile { flex-direction: column; align-items: stretch; }
    .test__zeile :deep(.btn) { width: 100%; min-height: 46px; }
}
</style>
