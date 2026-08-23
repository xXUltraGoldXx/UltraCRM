<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from '../components/Icon.vue';
import { COLOR_HEX } from '../fieldTypes';
import { bcp47Locale } from '../i18n';

const router = useRouter();
const { t } = useI18n();
const submissions = ref([]);
const templates = ref([]);
const loading = ref(false);
const showPicker = ref(false);
const filterStatus = ref('');

async function load() {
    loading.value = true;
    try {
        const params = {};
        if (filterStatus.value) params.status = filterStatus.value;
        const [subs, tpls] = await Promise.all([
            api.get('/form_submissions', { params }),
            api.get('/form_templates'),
        ]);
        submissions.value = subs.data['member'] || subs.data['hydra:member'] || [];
        templates.value = tpls.data['member'] || tpls.data['hydra:member'] || [];
    } finally {
        loading.value = false;
    }
}

const activeTemplates = computed(() => templates.value.filter((t) => t.active !== false));

function startNew(tpl) {
    showPicker.value = false;
    router.push({ name: 'submission-new', params: { templateId: tpl.id } });
}

function openPdf(s) {
    // Token muss mit, daher per fetch als Blob öffnen
    api.get(`/form_submissions/${s.id}/pdf`, { responseType: 'blob' }).then((res) => {
        const url = URL.createObjectURL(res.data);
        window.open(url, '_blank');
    });
}

function edit(s) {
    router.push({ name: 'submission-edit', params: { id: s.id } });
}

async function remove(s) {
    if (!confirm(t('submissions.confirmDelete'))) return;
    await api.delete(`/form_submissions/${s.id}`);
    await load();
}

function fmtDate(iso) {
    return new Date(iso).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

onMounted(load);
</script>

<template>
    <div class="subs">
        <div class="toolbar">
            <div class="filter-tabs">
                <button :class="{ active: filterStatus === '' }" @click="filterStatus = ''; load()">{{ $t('submissions.tabAll') }}</button>
                <button :class="{ active: filterStatus === 'completed' }" @click="filterStatus = 'completed'; load()">{{ $t('submissions.tabCompleted') }}</button>
                <button :class="{ active: filterStatus === 'draft' }" @click="filterStatus = 'draft'; load()">{{ $t('submissions.tabDraft') }}</button>
            </div>
            <button class="btn" @click="showPicker = true"><Icon name="plus" :size="17" /> {{ $t('submissions.newButton') }}</button>
        </div>

        <div class="card table-card">
            <table>
                <thead>
                    <tr>
                        <th>{{ $t('submissions.colForm') }}</th>
                        <th>{{ $t('submissions.colCustomer') }}</th>
                        <th>{{ $t('submissions.colCreated') }}</th>
                        <th>{{ $t('submissions.colStatus') }}</th>
                        <th class="right">{{ $t('submissions.colActions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="5" class="state">{{ $t('submissions.loadingText') }}</td></tr>
                    <tr v-else-if="!submissions.length"><td colspan="5" class="state">{{ $t('submissions.empty') }}</td></tr>
                    <tr v-for="s in submissions" :key="s.id">
                        <td><b>{{ s.templateName || $t('submissions.defaultFormName') }}</b></td>
                        <td>{{ s.customer?.company || '—' }}</td>
                        <td>{{ fmtDate(s.createdAt) }}</td>
                        <td>
                            <span class="status" :class="s.status">{{ s.status === 'completed' ? $t('submissions.statusCompleted') : $t('submissions.statusDraft') }}</span>
                        </td>
                        <td class="right">
                            <button class="icon-act" :title="$t('submissions.pdfTitle')" @click="openPdf(s)"><Icon name="file" :size="16" /></button>
                            <button class="icon-act" :title="$t('submissions.editTitle')" @click="edit(s)"><Icon name="edit" :size="16" /></button>
                            <button class="icon-act danger" :title="$t('submissions.deleteTitle')" @click="remove(s)"><Icon name="trash" :size="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Vorlagen-Auswahl -->
        <div v-if="showPicker" class="modal-backdrop" @click.self="showPicker = false">
            <div class="modal card">
                <div class="modal-head">
                    <h2>{{ $t('submissions.pickerTitle') }}</h2>
                    <button class="icon-close" @click="showPicker = false"><Icon name="x" :size="16" /></button>
                </div>
                <div class="picker-grid">
                    <button v-for="t in activeTemplates" :key="t.id" class="pick-tpl" @click="startNew(t)">
                        <div class="pt-icon" :style="{ background: (COLOR_HEX[t.color] || '#3b82f6') + '22', color: COLOR_HEX[t.color] || '#3b82f6' }">
                            <Icon :name="t.icon || 'file'" :size="20" />
                        </div>
                        <div>
                            <b>{{ t.name }}</b>
                            <span>{{ $t('submissions.pickerFields', t.schema?.length || 0) }}</span>
                        </div>
                    </button>
                    <p v-if="!activeTemplates.length" class="no-tpl">
                        {{ $t('submissions.pickerEmpty') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.subs { display: flex; flex-direction: column; gap: 18px; }
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; }
.filter-tabs { display: flex; gap: 6px; background: var(--surface-2); padding: 4px; border-radius: 10px; }
.filter-tabs button {
    background: none; border: none; cursor: pointer;
    padding: 7px 16px; border-radius: 8px;
    color: var(--ink-soft); font-size: 0.85rem; font-weight: 600;
}
.filter-tabs button.active { background: var(--surface); color: var(--ink); box-shadow: var(--shadow); }

.table-card { overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); padding: 14px 18px; border-bottom: 1px solid var(--line); }
td { padding: 13px 18px; border-bottom: 1px solid var(--line); font-size: 0.9rem; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--surface-2); }
.right { text-align: right; }
.state { text-align: center; color: var(--ink-soft); padding: 40px; }
.status { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.76rem; font-weight: 600; }
.status.completed { background: rgba(34,197,94,0.12); color: var(--green); }
.status.draft { background: rgba(245,158,11,0.14); color: var(--amber); }
.icon-act { background: none; border: none; cursor: pointer; color: var(--ink-soft); padding: 6px 7px; border-radius: 7px; transition: background 0.15s, color 0.15s; }
.icon-act:hover { background: var(--line); color: var(--ink); }
.icon-act.danger:hover { color: var(--red); }

.modal-backdrop { position: fixed; inset: 0; background: rgba(5,8,14,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.modal { width: 100%; max-width: 560px; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
.modal-head h2 { font-size: 1.08rem; }
.icon-close { background: var(--surface-2); border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.picker-grid { padding: 20px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pick-tpl { display: flex; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: none; cursor: pointer; text-align: left; transition: border-color 0.15s, transform 0.15s; }
.pick-tpl:hover { border-color: var(--accent); transform: translateY(-2px); }
.pt-icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pick-tpl b { display: block; font-size: 0.92rem; color: var(--ink); }
.pick-tpl span { color: var(--ink-soft); font-size: 0.78rem; }
.no-tpl { grid-column: 1 / -1; color: var(--ink-soft); font-size: 0.88rem; text-align: center; padding: 20px; }

@media (max-width: 520px) { .picker-grid { grid-template-columns: 1fr; } }
</style>
