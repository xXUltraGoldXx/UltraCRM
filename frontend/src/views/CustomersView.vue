<script setup>
import { ref, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import CustomerForm from '../components/CustomerForm.vue';
import Icon from '../components/Icon.vue';

const { t } = useI18n();

const customers = ref([]);
const loading = ref(false);
const search = ref('');
const total = ref(0);
const showForm = ref(false);
const editing = ref(null);
let searchTimer;

async function load() {
    loading.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.company = search.value.trim();
        const { data } = await api.get('/customers', { params });
        customers.value = data['member'] || data['hydra:member'] || [];
        total.value = data['totalItems'] ?? data['hydra:totalItems'] ?? customers.value.length;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

function openNew() {
    editing.value = null;
    showForm.value = true;
}
function openEdit(c) {
    editing.value = { ...c };
    showForm.value = true;
}
async function onSaved() {
    showForm.value = false;
    await load();
}
async function remove(c) {
    if (!confirm(t('customers.confirmDelete', { name: c.company }))) return;
    await api.delete(`/customers/${c.id}`);
    await load();
}

onMounted(load);
</script>

<template>
    <div class="customers">
        <div class="toolbar">
            <div class="search-box">
                <Icon name="search" :size="17" />
                <input v-model="search" class="search-input" type="search" :placeholder="$t('customers.searchPlaceholder')">
            </div>
            <button class="btn" @click="openNew"><Icon name="plus" :size="17" /> {{ $t('customers.addButton') }}</button>
        </div>

        <div class="card table-card">
            <table>
                <thead>
                    <tr>
                        <th>{{ $t('customers.colFirma') }}</th>
                        <th>{{ $t('customers.colContact') }}</th>
                        <th>{{ $t('customers.colLocation') }}</th>
                        <th>{{ $t('customers.colPhone') }}</th>
                        <th class="right">{{ $t('customers.colActions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="5" class="state">{{ $t('customers.loadingText') }}</td></tr>
                    <tr v-else-if="!customers.length"><td colspan="5" class="state">{{ $t('customers.empty') }}</td></tr>
                    <tr v-for="c in customers" :key="c.id">
                        <td><b>{{ c.company }}</b><small v-if="c.location">{{ c.location }}</small></td>
                        <td>{{ c.contact || '—' }}</td>
                        <td>{{ [c.zipCode, c.city].filter(Boolean).join(' ') || '—' }}</td>
                        <td>{{ c.phone || '—' }}</td>
                        <td class="right">
                            <button class="icon-act" :title="$t('customers.editTitle')" @click="openEdit(c)"><Icon name="edit" :size="16" /></button>
                            <button class="icon-act danger" :title="$t('customers.deleteTitle')" @click="remove(c)"><Icon name="trash" :size="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="count" v-if="!loading">{{ $t('customers.count', total) }}</p>

        <CustomerForm v-if="showForm" :customer="editing" @saved="onSaved" @close="showForm = false" />
    </div>
</template>

<style scoped>
.customers { display: flex; flex-direction: column; gap: 18px; }
.toolbar { display: flex; gap: 14px; justify-content: space-between; flex-wrap: wrap; }
.search-box {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 0 14px;
    flex: 1;
    max-width: 420px;
}
.search-input {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--ink);
    padding: 11px 0;
    font-size: 0.92rem;
}
.table-card { overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ink-soft);
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
}
td { padding: 13px 18px; border-bottom: 1px solid var(--line); font-size: 0.9rem; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--surface-2); }
td b { display: block; }
td small { color: var(--ink-soft); font-size: 0.78rem; }
.right { text-align: right; }
.state { text-align: center; color: var(--ink-soft); padding: 40px; }
.icon-act {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--ink-soft);
    padding: 6px 7px;
    border-radius: 7px;
    transition: background 0.15s, color 0.15s;
}
.icon-act:hover { background: var(--line); color: var(--ink); }
.icon-act.danger:hover { color: var(--red); }
.count { color: var(--ink-soft); font-size: 0.82rem; }
</style>
