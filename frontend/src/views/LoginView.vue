<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import UiButton from '../components/ui/UiButton.vue';
import UiField from '../components/ui/UiField.vue';

const auth = useAuthStore();
const router = useRouter();
const username = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    busy.value = true;
    try {
        await auth.login(username.value, password.value);
        router.push('/');
    } catch (e) {
        error.value = e?.response?.status === 401
            ? 'Benutzername oder Passwort stimmt nicht.'
            : 'Anmeldung gerade nicht möglich. Bitte später erneut versuchen.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="login">
        <form class="panel" @submit.prevent="submit">
            <span class="mark">U</span>
            <h1 class="t-large-title">UltraCRM</h1>
            <p class="t-subhead sub">Kundendaten, Vertrieb und Einwilligungen — auf eigenen Servern in Deutschland.</p>

            <div class="stack fields">
                <UiField v-model="username" label="Benutzername" autocomplete="username" placeholder="z. B. alexander" />
                <UiField v-model="password" label="Passwort" type="password" autocomplete="current-password" placeholder="••••••••" />
            </div>

            <p v-if="error" class="error t-footnote">{{ error }}</p>

            <UiButton type="submit" variant="primary" :disabled="busy || !username || !password">
                {{ busy ? 'Einen Moment…' : 'Anmelden' }}
            </UiButton>
        </form>
    </div>
</template>

<style scoped>
.login {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: var(--sp-6);
    background: var(--bg-grouped);
}
.panel {
    width: min(400px, 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--sp-3);
    padding: var(--sp-10) var(--sp-8);
    background: var(--bg-elevated);
    border: 1px solid var(--separator);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-card);
}
.mark {
    width: 46px; height: 46px;
    display: grid; place-items: center;
    border-radius: var(--radius-m);
    background: var(--label-primary);
    color: var(--bg-elevated);
    font-weight: 700; font-size: var(--text-title-3);
    margin-bottom: var(--sp-2);
}
.sub { margin: 0; max-width: 30ch; }
.fields { width: 100%; margin-top: var(--sp-5); text-align: left; }
.error { color: var(--danger); margin: 0; }
.panel :deep(.btn) { width: 100%; margin-top: var(--sp-2); padding-block: 12px; font-size: var(--text-body); }
</style>
