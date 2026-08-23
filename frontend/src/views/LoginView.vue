<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();

const username = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        await auth.login(username.value, password.value);
        router.push({ name: 'dashboard' });
    } catch (e) {
        error.value = e.response?.status === 401
            ? t('login.errorInvalid')
            : t('login.errorGeneric');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="login-page">
        <div class="login-orb orb-a"></div>
        <div class="login-orb orb-b"></div>

        <form class="login-card card" @submit.prevent="submit">
            <div class="login-logo">
                <div class="logo-mark">U</div>
                <div>
                    <b>{{ $t('app.name') }}</b>
                    <span>{{ $t('app.tagline') }}</span>
                </div>
            </div>

            <label>
                {{ $t('login.username') }}
                <input v-model="username" class="input" type="text" autocomplete="username" required autofocus>
            </label>
            <label>
                {{ $t('login.password') }}
                <input v-model="password" class="input" type="password" autocomplete="current-password" required>
            </label>

            <p v-if="error" class="login-error">{{ error }}</p>

            <button class="btn login-btn" type="submit" :disabled="loading">
                {{ loading ? $t('login.submitting') : $t('login.submit') }}
            </button>
        </form>
    </div>
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    position: relative;
    overflow: hidden;
}
.login-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(120px);
    opacity: 0.25;
    pointer-events: none;
}
.orb-a { width: 460px; height: 460px; background: var(--accent); top: -140px; right: -100px; }
.orb-b { width: 400px; height: 400px; background: #8b5cf6; bottom: -120px; left: -110px; }

.login-card {
    width: 100%;
    max-width: 400px;
    padding: 40px 36px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    position: relative;
    z-index: 1;
}
.login-logo {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 10px;
}
.logo-mark {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff;
    font-weight: 800;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-logo b { display: block; font-size: 1.05rem; }
.login-logo span { color: var(--ink-soft); font-size: 0.8rem; }

label {
    display: flex;
    flex-direction: column;
    gap: 7px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--ink-soft);
}
.login-error {
    color: var(--red);
    font-size: 0.86rem;
}
.login-btn {
    justify-content: center;
    padding: 13px;
    margin-top: 6px;
}
</style>
