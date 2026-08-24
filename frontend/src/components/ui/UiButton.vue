<script setup>
defineProps({
    variant: { type: String, default: 'secondary' }, // primary | secondary | quiet | danger
    size: { type: String, default: 'md' },           // sm | md
    type: { type: String, default: 'button' },
});
</script>

<template>
    <button :type="type" class="btn" :class="[`btn--${variant}`, `btn--${size}`]">
        <slot />
    </button>
</template>

<style scoped>
/* Pill shape and calm state transitions, like native controls. */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--sp-2);
    font-family: inherit;
    font-weight: 500;
    border: 1px solid transparent;
    border-radius: var(--radius-pill);
    cursor: pointer;
    transition: background-color .18s ease, border-color .18s ease, opacity .18s ease;
    white-space: nowrap;
}
.btn--md { font-size: var(--text-subhead); padding: 9px 18px; }
.btn--sm { font-size: var(--text-footnote); padding: 6px 13px; }

.btn--primary { background: var(--accent); color: #fff; }
.btn--primary:hover { background: var(--accent-hover); }

.btn--secondary {
    background: var(--fill-quaternary);
    color: var(--label-primary);
    border-color: var(--separator);
}
.btn--secondary:hover { background: var(--fill-tertiary); }

.btn--quiet { background: transparent; color: var(--accent); padding-inline: 8px; }
.btn--quiet:hover { background: var(--accent-quiet); }

.btn--danger { background: transparent; color: var(--danger); border-color: var(--separator); }
.btn--danger:hover { background: color-mix(in srgb, var(--danger) 10%, transparent); }

.btn:disabled { opacity: .4; cursor: not-allowed; }
</style>
