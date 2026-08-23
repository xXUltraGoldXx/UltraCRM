<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from './Icon.vue';

const props = defineProps({ customer: { type: Object, default: null } });
const emit = defineEmits(['saved', 'close']);
const { t } = useI18n();

const isEdit = !!props.customer?.id;
const form = ref({
    company: props.customer?.company || '',
    contact: props.customer?.contact || '',
    location: props.customer?.location || '',
    phone: props.customer?.phone || '',
    email: props.customer?.email || '',
    street: props.customer?.street || '',
    zipCode: props.customer?.zipCode || '',
    city: props.customer?.city || '',
    notes: props.customer?.notes || '',
});
const saving = ref(false);
const error = ref('');

async function save() {
    error.value = '';
    if (!form.value.company.trim()) {
        error.value = t('customerForm.errorRequired');
        return;
    }
    saving.value = true;
    try {
        const headers = { 'Content-Type': isEdit ? 'application/merge-patch+json' : 'application/ld+json' };
        if (isEdit) {
            await api.patch(`/customers/${props.customer.id}`, form.value, { headers });
        } else {
            await api.post('/customers', form.value, { headers });
        }
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.detail || t('customerForm.errorSave');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal card">
            <div class="modal-head">
                <h2>{{ isEdit ? $t('customerForm.titleEdit') : $t('customerForm.titleNew') }}</h2>
                <button class="icon-close" @click="emit('close')"><Icon name="x" :size="16" /></button>
            </div>

            <div class="modal-body">
                <label class="full">{{ $t('customerForm.company') }}
                    <input v-model="form.company" class="input" autofocus>
                </label>
                <label>{{ $t('customerForm.contact') }}
                    <input v-model="form.contact" class="input">
                </label>
                <label>{{ $t('customerForm.location') }}
                    <input v-model="form.location" class="input">
                </label>
                <label>{{ $t('customerForm.phone') }}
                    <input v-model="form.phone" class="input">
                </label>
                <label>{{ $t('customerForm.email') }}
                    <input v-model="form.email" class="input" type="email">
                </label>
                <label class="full">{{ $t('customerForm.street') }}
                    <input v-model="form.street" class="input">
                </label>
                <label>{{ $t('customerForm.zip') }}
                    <input v-model="form.zipCode" class="input">
                </label>
                <label>{{ $t('customerForm.city') }}
                    <input v-model="form.city" class="input">
                </label>
                <label class="full">{{ $t('customerForm.notes') }}
                    <textarea v-model="form.notes" class="input" rows="2"></textarea>
                </label>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div class="modal-foot">
                <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.cancel') }}</button>
                <button class="btn" :disabled="saving" @click="save">
                    {{ saving ? $t('common.saving') : $t('common.save') }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(5, 8, 14, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 100;
}
.modal { width: 100%; max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; }
.modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
}
.modal-head h2 { font-size: 1.1rem; }
.icon-close {
    background: var(--surface-2);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--ink);
}
.modal-body {
    padding: 22px 24px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    overflow-y: auto;
}
label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--ink-soft);
}
label.full { grid-column: 1 / -1; }
textarea.input { resize: vertical; }
.form-error { color: var(--red); font-size: 0.85rem; padding: 0 24px; }
.modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 18px 24px;
    border-top: 1px solid var(--line);
}
@media (max-width: 520px) {
    .modal-body { grid-template-columns: 1fr; }
}
</style>
