<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from '../components/Icon.vue';
import SparePartsField from '../components/SparePartsField.vue';
import SignatureField from '../components/SignatureField.vue';
import CustomerPicker from '../components/CustomerPicker.vue';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const template = ref(null);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const values = reactive({});
const customerIri = ref(null);
const isEdit = computed(() => route.name === 'submission-edit');
const submissionId = ref(null);

onMounted(async () => {
    try {
        if (isEdit.value) {
            const { data } = await api.get(`/form_submissions/${route.params.id}`);
            submissionId.value = data.id;
            customerIri.value = data.customer?.['@id'] || null;
            const tplId = (data.template?.['@id'] || data.template || '').split('/').pop();
            const { data: tpl } = await api.get(`/form_templates/${tplId}`);
            template.value = tpl;
            Object.assign(values, data.data || {});
        } else {
            const { data: tpl } = await api.get(`/form_templates/${route.params.templateId}`);
            template.value = tpl;
        }
        // Defaults für Spezialfelder
        for (const f of template.value.schema || []) {
            if (values[f.id] === undefined) {
                if (f.type === 'spareparts') values[f.id] = [];
                else if (f.type === 'checkbox') values[f.id] = false;
                else values[f.id] = '';
            }
        }
    } finally {
        loading.value = false;
    }
});

function validate() {
    for (const f of template.value.schema || []) {
        if (f.required && f.type !== 'heading') {
            const v = f.type === 'customer' ? customerIri.value : values[f.id];
            if (!v || (Array.isArray(v) && !v.length)) {
                error.value = t('fillSubmission.errorRequiredField', { field: f.label || t('fillSubmission.errorRequiredFieldFallback') });
                return false;
            }
        }
    }
    return true;
}

async function save(status) {
    error.value = '';
    if (status === 'completed' && !validate()) return;
    saving.value = true;
    try {
        const payload = {
            template: template.value['@id'] || `/api/form_templates/${template.value.id}`,
            customer: customerIri.value,
            data: { ...values },
            status,
        };
        let id = submissionId.value;
        if (isEdit.value) {
            await api.patch(`/form_submissions/${id}`, payload, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        } else {
            const { data } = await api.post('/form_submissions', payload, { headers: { 'Content-Type': 'application/ld+json' } });
            id = data.id;
        }
        router.push({ name: 'submissions' });
    } catch (e) {
        error.value = e.response?.data?.detail || t('fillSubmission.errorSave');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="fill">
        <div v-if="loading" class="state">{{ $t('fillSubmission.loadingText') }}</div>
        <template v-else-if="template">
            <div class="fill-head card">
                <button class="icon-btn" :title="$t('fillSubmission.backTitle')" @click="router.back()"><Icon name="back" :size="18" /></button>
                <div>
                    <h2>{{ template.name }}</h2>
                    <span>{{ isEdit ? $t('fillSubmission.editMode') : $t('fillSubmission.newMode') }}</span>
                </div>
            </div>

            <div class="form card">
                <div v-for="f in template.schema" :key="f.id"
                    class="field" :class="{ half: f.width === 'half' && f.type !== 'heading' }">
                    <!-- Überschrift -->
                    <h3 v-if="f.type === 'heading'" class="f-heading">{{ f.label }}</h3>

                    <template v-else>
                        <label class="f-label">{{ f.label }}<em v-if="f.required">*</em></label>

                        <input v-if="f.type === 'text'" v-model="values[f.id]" class="input" :placeholder="f.placeholder">
                        <textarea v-else-if="f.type === 'textarea'" v-model="values[f.id]" class="input" rows="3" :placeholder="f.placeholder"></textarea>
                        <input v-else-if="f.type === 'number'" v-model="values[f.id]" class="input" type="number" :placeholder="f.placeholder">
                        <input v-else-if="f.type === 'date'" v-model="values[f.id]" class="input" type="date">
                        <input v-else-if="f.type === 'time'" v-model="values[f.id]" class="input" type="time">
                        <select v-else-if="f.type === 'select'" v-model="values[f.id]" class="input">
                            <option value="">{{ $t('fillSubmission.selectPlaceholder') }}</option>
                            <option v-for="(o, oi) in f.options" :key="oi" :value="o">{{ o }}</option>
                        </select>
                        <label v-else-if="f.type === 'checkbox'" class="f-check">
                            <input type="checkbox" v-model="values[f.id]"> {{ f.label }}
                        </label>
                        <CustomerPicker v-else-if="f.type === 'customer'" v-model="customerIri" />
                        <SparePartsField v-else-if="f.type === 'spareparts'" v-model="values[f.id]" />
                        <SignatureField v-else-if="f.type === 'signature'" v-model="values[f.id]" />
                    </template>
                </div>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div class="fill-actions">
                <button class="btn btn-ghost" :disabled="saving" @click="save('draft')">{{ $t('fillSubmission.saveDraft') }}</button>
                <button class="btn" :disabled="saving" @click="save('completed')">
                    {{ saving ? $t('fillSubmission.saveCompleting') : $t('fillSubmission.saveComplete') }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.fill { display: flex; flex-direction: column; gap: 16px; max-width: 820px; }
.state { color: var(--ink-soft); padding: 40px; text-align: center; }
.fill-head { display: flex; align-items: center; gap: 14px; padding: 16px 20px; }
.fill-head h2 { font-size: 1.15rem; }
.fill-head span { color: var(--ink-soft); font-size: 0.85rem; }

.form { padding: 26px; display: flex; flex-wrap: wrap; gap: 18px; }
.field { width: 100%; display: flex; flex-direction: column; gap: 7px; }
.field.half { width: calc(50% - 9px); }
.f-heading {
    width: 100%;
    font-size: 1.02rem;
    padding-bottom: 6px;
    border-bottom: 2px solid var(--accent);
    margin-top: 6px;
}
.f-label { font-size: 0.83rem; font-weight: 600; color: var(--ink-soft); }
.f-label em { color: var(--red); font-style: normal; margin-left: 2px; }
.f-check { display: flex; align-items: center; gap: 9px; font-size: 0.9rem; }
textarea.input { resize: vertical; }

.form-error { color: var(--red); font-size: 0.88rem; }
.fill-actions { display: flex; justify-content: flex-end; gap: 12px; }

@media (max-width: 640px) {
    .field.half { width: 100%; }
}
</style>
