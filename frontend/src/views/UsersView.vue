<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from '../components/Icon.vue';
import UserForm from '../components/UserForm.vue';

const { t } = useI18n();
const users = ref([]);
const loading = ref(false);
const showForm = ref(false);
const editing = ref(null);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/users');
        users.value = data['member'] || data['hydra:member'] || [];
    } finally {
        loading.value = false;
    }
}

function openNew() {
    editing.value = null;
    showForm.value = true;
}
function openEdit(u) {
    editing.value = { ...u };
    showForm.value = true;
}
async function onSaved() {
    showForm.value = false;
    await load();
}
async function toggleActive(u) {
    await api.patch(`/users/${u.id}`, { active: !u.active }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
    await load();
}
async function remove(u) {
    if (!confirm(t('users.confirmDelete', { name: u.displayName }))) return;
    await api.delete(`/users/${u.id}`);
    await load();
}

function roleLabel(u) {
    return u.roles?.includes('ROLE_ADMIN') ? t('users.roleAdmin') : t('users.roleUser');
}

onMounted(load);
</script>

<template>
    <div class="users">
        <div class="head">
            <p class="lead">{{ $t('users.lead') }}</p>
            <button class="btn" @click="openNew"><Icon name="plus" :size="17" /> {{ $t('users.addButton') }}</button>
        </div>

        <div class="card table-card">
            <table>
                <thead>
                    <tr>
                        <th>{{ $t('users.colName') }}</th>
                        <th>{{ $t('users.colUsername') }}</th>
                        <th>{{ $t('users.colRole') }}</th>
                        <th>{{ $t('users.colStatus') }}</th>
                        <th class="right">{{ $t('users.colActions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="5" class="state">{{ $t('users.loadingText') }}</td></tr>
                    <tr v-for="u in users" :key="u.id">
                        <td>
                            <div class="u-name">
                                <span class="u-avatar">{{ (u.displayName || '?').slice(0, 2).toUpperCase() }}</span>
                                <div>
                                    <b>{{ u.displayName }}</b>
                                    <small v-if="u.email">{{ u.email }}</small>
                                </div>
                            </div>
                        </td>
                        <td>{{ u.username }}</td>
                        <td>
                            <span class="role" :class="{ admin: u.roles?.includes('ROLE_ADMIN') }">{{ roleLabel(u) }}</span>
                        </td>
                        <td>
                            <button class="status-toggle" :class="{ on: u.active }" @click="toggleActive(u)">
                                {{ u.active ? $t('users.statusActive') : $t('users.statusInactive') }}
                            </button>
                        </td>
                        <td class="right">
                            <button class="icon-act" :title="$t('users.editTitle')" @click="openEdit(u)"><Icon name="edit" :size="16" /></button>
                            <button class="icon-act danger" :title="$t('users.deleteTitle')" @click="remove(u)"><Icon name="trash" :size="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UserForm v-if="showForm" :user="editing" @saved="onSaved" @close="showForm = false" />
    </div>
</template>

<style scoped>
.users { display: flex; flex-direction: column; gap: 18px; }
.head { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; }
.lead { color: var(--ink-soft); font-size: 0.92rem; max-width: 620px; }
.table-card { overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); padding: 14px 18px; border-bottom: 1px solid var(--line); }
td { padding: 12px 18px; border-bottom: 1px solid var(--line); font-size: 0.9rem; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--surface-2); }
.right { text-align: right; }
.state { text-align: center; color: var(--ink-soft); padding: 40px; }
.u-name { display: flex; align-items: center; gap: 11px; }
.u-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff; font-size: 0.74rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.u-name b { display: block; }
.u-name small { color: var(--ink-soft); font-size: 0.78rem; }
.role { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 0.76rem; font-weight: 600; background: var(--surface-2); color: var(--ink-soft); }
.role.admin { background: rgba(139,92,246,0.14); color: #a78bfa; }
.status-toggle { border: none; cursor: pointer; padding: 4px 12px; border-radius: 999px; font-size: 0.76rem; font-weight: 600; background: rgba(245,158,11,0.14); color: var(--amber); }
.status-toggle.on { background: rgba(34,197,94,0.12); color: var(--green); }
.icon-act { background: none; border: none; cursor: pointer; color: var(--ink-soft); padding: 6px 7px; border-radius: 7px; transition: background 0.15s, color 0.15s; }
.icon-act:hover { background: var(--line); color: var(--ink); }
.icon-act.danger:hover { color: var(--red); }
</style>
