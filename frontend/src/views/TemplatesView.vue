<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../api';
import { COLOR_HEX } from '../fieldTypes';
import Icon from '../components/Icon.vue';

const router = useRouter();
const { t } = useI18n();
const templates = ref([]);
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/form_templates');
        templates.value = data['member'] || data['hydra:member'] || [];
    } finally {
        loading.value = false;
    }
}

function openDesigner(id) {
    router.push({ name: 'template-designer', params: { id: id || 'neu' } });
}

async function duplicate(tpl) {
    const { data } = await api.post('/form_templates', {
        name: tpl.name + t('templates.copySuffix'),
        icon: tpl.icon,
        color: tpl.color,
        description: tpl.description,
        schema: tpl.schema,
    }, { headers: { 'Content-Type': 'application/ld+json' } });
    openDesigner(data.id);
}

async function remove(tpl) {
    if (!confirm(t('templates.confirmDelete', { name: tpl.name }))) return;
    await api.delete(`/form_templates/${tpl.id}`);
    await load();
}

onMounted(load);
</script>

<template>
    <div class="templates">
        <div class="head">
            <p class="lead">{{ $t('templates.lead') }}</p>
            <button class="btn" @click="openDesigner()"><Icon name="plus" :size="17" /> {{ $t('templates.addButton') }}</button>
        </div>

        <div v-if="loading" class="state">{{ $t('templates.loadingText') }}</div>
        <div v-else-if="!templates.length" class="empty card">
            <div class="empty-icon"><Icon name="templates" :size="30" /></div>
            <h3>{{ $t('templates.emptyTitle') }}</h3>
            <p>{{ $t('templates.emptyText') }}</p>
            <button class="btn" @click="openDesigner()"><Icon name="plus" :size="17" /> {{ $t('templates.addFirstButton') }}</button>
        </div>

        <div v-else class="grid">
            <div v-for="t in templates" :key="t.id" class="tpl card" @click="openDesigner(t.id)">
                <div class="tpl-icon" :style="{ background: (COLOR_HEX[t.color] || '#3b82f6') + '22', color: COLOR_HEX[t.color] || '#3b82f6' }">
                    <Icon :name="t.icon || 'file'" :size="22" />
                </div>
                <div class="tpl-info">
                    <b>{{ t.name }}</b>
                    <span>{{ $t('templates.fieldCount', t.schema?.length || 0) }}<template v-if="!t.active"> · {{ $t('templates.inactive') }}</template></span>
                </div>
                <div class="tpl-actions" @click.stop>
                    <button class="icon-act" :title="$t('templates.duplicateTitle')" @click="duplicate(t)"><Icon name="copy" :size="16" /></button>
                    <button class="icon-act danger" :title="$t('templates.deleteTitle')" @click="remove(t)"><Icon name="trash" :size="16" /></button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.templates { display: flex; flex-direction: column; gap: 20px; }
.head { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; }
.lead { color: var(--ink-soft); font-size: 0.92rem; max-width: 640px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.tpl {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    cursor: pointer;
    transition: transform 0.18s, border-color 0.18s;
}
.tpl:hover { transform: translateY(-3px); border-color: var(--accent); }
.tpl-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.tpl-info { flex: 1; min-width: 0; }
.tpl-info b { display: block; }
.tpl-info span { color: var(--ink-soft); font-size: 0.8rem; }
.tpl-actions { display: flex; gap: 4px; }
.icon-act {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--ink-soft);
    padding: 6px;
    border-radius: 7px;
    transition: background 0.15s, color 0.15s;
}
.icon-act:hover { background: var(--line); color: var(--ink); }
.icon-act.danger:hover { color: var(--red); }
.state { color: var(--ink-soft); padding: 40px; text-align: center; }
.empty {
    padding: 60px 32px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.empty-icon {
    width: 64px; height: 64px;
    border-radius: 16px;
    background: var(--surface-2);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
}
.empty h3 { font-size: 1.15rem; }
.empty p { color: var(--ink-soft); font-size: 0.9rem; margin-bottom: 8px; }
</style>
