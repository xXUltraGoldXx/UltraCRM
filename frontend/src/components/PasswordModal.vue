<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from './Icon.vue';

const emit = defineEmits(['close', 'changed']);
const { t } = useI18n();

const form = ref({ currentPassword: '', newPassword: '', repeatPassword: '' });
const saving = ref(false);
const error = ref('');
const success = ref(false);

// Paket 1, Punkt 3: die API antwortet bei 400/403/422 mit einer generischen
// Symfony-Fehlerseite (HTML, kein JSON) -- dieser Controller ist ein PLAIN
// Symfony-Controller, nicht ueber die API-Platform-Fehlerserialisierung
// gefuehrt (bewusst so belassen, siehe MeController-Kommentar: "kein Detail-
// Leak"). Deshalb hier ueber den HTTP-Status statt e.response?.data?.detail
// unterschieden.
async function save() {
    error.value = '';
    if (!form.value.currentPassword || !form.value.newPassword) {
        error.value = t('password.errorTooShort');
        return;
    }
    if (form.value.newPassword.length < 8) {
        error.value = t('password.errorTooShort');
        return;
    }
    if (form.value.newPassword !== form.value.repeatPassword) {
        error.value = t('password.errorMismatch');
        return;
    }

    saving.value = true;
    try {
        await api.post('/me/password', {
            currentPassword: form.value.currentPassword,
            newPassword: form.value.newPassword,
        });
        success.value = true;
        setTimeout(() => emit('changed'), 1800);
    } catch (e) {
        if (e.response?.status === 403) {
            error.value = t('password.errorWrong');
        } else if (e.response?.status === 422 || e.response?.status === 400) {
            error.value = t('password.errorTooShort');
        } else {
            error.value = t('password.errorGeneric');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="!success && emit('close')">
        <div class="modal card">
            <div class="modal-head">
                <h2>{{ $t('password.title') }}</h2>
                <button v-if="!success" class="icon-close" @click="emit('close')"><Icon name="x" :size="16" /></button>
            </div>

            <div v-if="success" class="modal-body success-body">
                <Icon name="check" :size="28" />
                <p>{{ $t('password.successMessage') }}</p>
            </div>
            <div v-else class="modal-body">
                <label>{{ $t('password.currentLabel') }}
                    <input v-model="form.currentPassword" class="input" type="password" autocomplete="current-password" autofocus>
                </label>
                <label>{{ $t('password.newLabel') }}
                    <input v-model="form.newPassword" class="input" type="password" autocomplete="new-password">
                </label>
                <label>{{ $t('password.repeatLabel') }}
                    <input v-model="form.repeatPassword" class="input" type="password" autocomplete="new-password"
                        @keyup.enter="save">
                </label>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div v-if="!success" class="modal-foot">
                <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.cancel') }}</button>
                <button class="btn" :disabled="saving" @click="save">{{ saving ? $t('password.submitting') : $t('password.submit') }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(5,8,14,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.modal { width: 100%; max-width: 420px; display: flex; flex-direction: column; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
.modal-head h2 { font-size: 1.1rem; }
.icon-close { background: var(--surface-2); border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.modal-body { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); }
.form-error { color: var(--red); font-size: 0.86rem; padding: 0 24px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 12px; padding: 18px 24px; border-top: 1px solid var(--line); }
.success-body { align-items: center; text-align: center; color: var(--green); padding: 34px 24px; }
.success-body p { color: var(--ink); font-size: 0.92rem; }
</style>
