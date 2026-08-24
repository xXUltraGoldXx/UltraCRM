<script setup>
defineProps({ tone: { type: String, default: 'neutral' } }); // neutral | positive | warn | quiet
</script>

<template>
    <span class="badge" :class="`badge--${tone}`"><slot /></span>
</template>

<style scoped>
.badge {
    display: inline-flex; align-items: center;
    padding: 3px 9px;
    border-radius: var(--radius-pill);
    font-size: var(--text-caption);
    font-weight: 600;
    letter-spacing: 0.01em;
    white-space: nowrap;
}
.badge--neutral { background: var(--fill-tertiary); color: var(--label-secondary); }
.badge--positive { background: color-mix(in srgb, var(--success) 16%, transparent); color: var(--success); }
.badge--warn { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--warning); }
.badge--quiet { background: var(--accent-quiet); color: var(--accent); }
</style>
