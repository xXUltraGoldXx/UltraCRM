<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from './Icon.vue';

const emit = defineEmits(['saved', 'close']);
const { t } = useI18n();

const form = ref({ startDate: '', endDate: '', reason: '', representative: '' });
const saving = ref(false);
const error = ref('');

async function save() {
    error.value = '';
    if (!form.value.startDate || !form.value.endDate) {
        error.value = t('holidayForm.errorDatesRequired');
        return;
    }
    if (form.value.endDate < form.value.startDate) {
        error.value = t('holidayForm.errorEndBeforeStart');
        return;
    }
    saving.value = true;
    try {
        await api.post('/holiday_requests', {
            startsAt: form.value.startDate,
            endsAt: form.value.endDate,
            reason: form.value.reason || null,
            representative: form.value.representative || null,
        }, { headers: { 'Content-Type': 'application/ld+json' } });
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.detail || t('holidayForm.errorSave');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal card">
            <div class="modal-head">
                <h2>{{ $t('holidayForm.title') }}</h2>
                <button class="icon-close" @click="emit('close')"><Icon name="x" :size="16" /></button>
            </div>

            <div class="modal-body">
                <div class="grid2">
                    <label>{{ $t('holidayForm.from') }}
                        <input type="date" v-model="form.startDate" class="input">
                    </label>
                    <label>{{ $t('holidayForm.to') }}
                        <input type="date" v-model="form.endDate" class="input">
                    </label>
                </div>
                <label>{{ $t('holidayForm.representative') }}
                    <input v-model="form.representative" class="input" :placeholder="$t('holidayForm.representativePlaceholder')">
                </label>
                <label>{{ $t('holidayForm.note') }}
                    <textarea v-model="form.reason" class="input" rows="3" :placeholder="$t('holidayForm.notePlaceholder')"></textarea>
                </label>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div class="modal-foot">
                <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.cancel') }}</button>
                <button class="btn" :disabled="saving" @click="save">{{ saving ? $t('common.saving') : $t('holidayForm.submit') }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(5,8,14,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.modal { width: 100%; max-width: 480px; max-height: 92vh; display: flex; flex-direction: column; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
.modal-head h2 { font-size: 1.1rem; }
.icon-close { background: var(--surface-2); border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.modal-body { padding: 22px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
textarea.input { resize: vertical; font-family: inherit; }
.form-error { color: var(--red); font-size: 0.86rem; padding: 0 24px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 12px; padding: 18px 24px; border-top: 1px solid var(--line); }

@media (max-width: 520px) { .grid2 { grid-template-columns: 1fr; } }
</style>
