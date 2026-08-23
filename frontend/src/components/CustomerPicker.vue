<script setup>
import { ref, watch, onMounted } from 'vue';
import api from '../api';
import Icon from './Icon.vue';

const props = defineProps({ modelValue: { type: [String, Object], default: null } });
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const results = ref([]);
const open = ref(false);
const selected = ref(null);
let timer;

async function search() {
    const params = {};
    if (query.value.trim()) params.company = query.value.trim();
    const { data } = await api.get('/customers', { params });
    results.value = data['member'] || data['hydra:member'] || [];
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
});

function pick(c) {
    selected.value = c;
    emit('update:modelValue', c['@id'] || `/api/customers/${c.id}`);
    open.value = false;
    query.value = '';
}
function clearSel() {
    selected.value = null;
    emit('update:modelValue', null);
}

onMounted(async () => {
    // Vorbelegten Kunden auflösen
    if (props.modelValue) {
        const iri = typeof props.modelValue === 'string' ? props.modelValue : props.modelValue['@id'];
        if (iri) {
            try {
                const { data } = await api.get(iri.replace('/api', ''));
                selected.value = data;
            } catch { /* ignore */ }
        }
    }
});
</script>

<template>
    <div class="picker">
        <div v-if="selected" class="chosen">
            <div>
                <b>{{ selected.company }}</b>
                <span v-if="selected.city">{{ selected.city }}</span>
            </div>
            <button class="unpick" :title="$t('customerPicker.clear')" @click="clearSel"><Icon name="x" :size="15" /></button>
        </div>
        <div v-else class="picker-input" @click="open = true; search()">
            <Icon name="search" :size="16" />
            <input v-model="query" class="pi-field" :placeholder="$t('customerPicker.placeholder')"
                @focus="open = true; search()">
        </div>
        <div v-if="open && !selected" class="dropdown card">
            <button v-for="c in results" :key="c.id" class="drop-item" @click="pick(c)">
                <b>{{ c.company }}</b>
                <span v-if="c.city">{{ c.city }}</span>
            </button>
            <p v-if="!results.length" class="drop-empty">{{ $t('customerPicker.empty') }}</p>
        </div>
    </div>
</template>

<style scoped>
.picker { position: relative; }
.picker-input {
    display: flex; align-items: center; gap: 9px;
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--surface-2);
    cursor: text;
    color: var(--ink-soft);
}
.pi-field { flex: 1; background: none; border: none; outline: none; color: var(--ink); font-size: 0.92rem; }
.chosen {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid var(--accent);
    border-radius: 10px;
    background: var(--surface-2);
}
.chosen b { display: block; font-size: 0.92rem; }
.chosen span { color: var(--ink-soft); font-size: 0.8rem; }
.unpick { background: none; border: none; color: var(--ink-soft); cursor: pointer; padding: 4px; border-radius: 6px; }
.unpick:hover { color: var(--red); }
.dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    margin-top: 6px; z-index: 30;
    max-height: 240px; overflow-y: auto; padding: 6px;
}
.drop-item {
    display: block; width: 100%; text-align: left;
    background: none; border: none; cursor: pointer;
    padding: 9px 12px; border-radius: 8px; color: var(--ink);
}
.drop-item:hover { background: var(--surface-2); }
.drop-item b { display: block; font-size: 0.88rem; }
.drop-item span { color: var(--ink-soft); font-size: 0.78rem; }
.drop-empty { color: var(--ink-soft); font-size: 0.85rem; padding: 12px; text-align: center; }
</style>
