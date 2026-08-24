<script setup>
defineProps({
    label: String,
    modelValue: [String, Number],
    type: { type: String, default: 'text' },
    placeholder: String,
    hint: String,
    autocomplete: String,
});
defineEmits(['update:modelValue']);
</script>

<template>
    <label class="field">
        <span v-if="label" class="field__label">{{ label }}</span>
        <input :type="type" :value="modelValue" :placeholder="placeholder"
               :autocomplete="autocomplete"
               @input="$emit('update:modelValue', $event.target.value)" />
        <span v-if="hint" class="t-footnote">{{ hint }}</span>
    </label>
</template>

<style scoped>
.field { display: flex; flex-direction: column; gap: var(--sp-2); }
.field__label { font-size: var(--text-footnote); font-weight: 600; color: var(--label-secondary); }
input {
    font-family: inherit;
    font-size: var(--text-body);
    color: var(--label-primary);
    background: var(--bg-input);
    border: 1px solid var(--separator);
    border-radius: var(--radius-m);
    padding: 11px 14px;
    transition: border-color .18s ease, box-shadow .18s ease;
}
input::placeholder { color: var(--label-tertiary); }
input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px var(--accent-quiet);
}
</style>
