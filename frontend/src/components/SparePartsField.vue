<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({ modelValue: { type: Array, default: () => [] } });
const emit = defineEmits(['update:modelValue']);

const rows = computed({
    get: () => props.modelValue || [],
    set: (v) => emit('update:modelValue', v),
});

function addRow() {
    rows.value = [...rows.value, { name: '', snOld: '', snNew: '' }];
}
function removeRow(i) {
    rows.value = rows.value.filter((_, idx) => idx !== i);
}
function update(i, key, val) {
    const copy = rows.value.map((r) => ({ ...r }));
    copy[i][key] = val;
    rows.value = copy;
}
</script>

<template>
    <div class="parts">
        <table v-if="rows.length">
            <thead>
                <tr>
                    <th>{{ $t('spareParts.colName') }}</th>
                    <th>{{ $t('spareParts.colSnOld') }}</th>
                    <th>{{ $t('spareParts.colSnNew') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, i) in rows" :key="i">
                    <td><input class="cell" :value="r.name" @input="update(i, 'name', $event.target.value)" :placeholder="$t('spareParts.namePlaceholder')"></td>
                    <td><input class="cell" :value="r.snOld" @input="update(i, 'snOld', $event.target.value)"></td>
                    <td><input class="cell" :value="r.snNew" @input="update(i, 'snNew', $event.target.value)"></td>
                    <td><button class="row-del" :title="$t('spareParts.removeTitle')" @click="removeRow(i)"><Icon name="x" :size="14" /></button></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="parts-empty">{{ $t('spareParts.empty') }}</p>
        <button class="add-row" @click="addRow"><Icon name="plus" :size="15" /> {{ $t('spareParts.add') }}</button>
    </div>
</template>

<style scoped>
.parts { border: 1px solid var(--line); border-radius: 10px; padding: 12px; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); padding: 4px 6px; }
td { padding: 3px 4px; }
.cell {
    width: 100%;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--surface-2);
    color: var(--ink);
    font-size: 0.85rem;
    outline: none;
}
.cell:focus { border-color: var(--accent); }
.row-del { background: none; border: none; color: var(--ink-soft); cursor: pointer; padding: 6px; border-radius: 6px; }
.row-del:hover { color: var(--red); background: var(--line); }
.parts-empty { color: var(--ink-soft); font-size: 0.85rem; padding: 6px 4px 12px; }
.add-row {
    display: flex; align-items: center; gap: 6px;
    background: none; border: 1px dashed var(--line); color: var(--ink-soft);
    padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; margin-top: 8px;
}
.add-row:hover { border-color: var(--accent); color: var(--accent); }
</style>
