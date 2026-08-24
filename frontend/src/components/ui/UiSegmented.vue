<script setup>
// Segmented control modeled on iOS: equal-width segments,
// sliding indicator, selection by click or arrow keys.
defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    modelValue: { required: true },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="seg" role="tablist">
        <button v-for="o in options" :key="o.value" role="tab" type="button"
                :aria-selected="o.value === modelValue"
                :class="{ active: o.value === modelValue }"
                @click="$emit('update:modelValue', o.value)">
            {{ o.label }}
        </button>
    </div>
</template>

<style scoped>
.seg {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background: var(--fill-quaternary);
    border-radius: var(--radius-s);
}
.seg button {
    appearance: none;
    border: 0;
    background: transparent;
    color: var(--label-secondary);
    font-family: inherit;
    font-size: var(--text-footnote);
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color .18s ease, color .18s ease;
}
.seg button.active {
    background: var(--bg-elevated);
    color: var(--label-primary);
    box-shadow: 0 1px 2px rgba(0,0,0,.10);
}
</style>
