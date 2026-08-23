<script setup>
import { useRoute } from 'vue-router';
import Icon from '../components/Icon.vue';

const route = useRoute();
</script>

<template>
    <div class="placeholder card">
        <div class="ph-icon"><Icon name="settings" :size="26" /></div>
        <h2>{{ $t(route.meta.titleKey) }}</h2>
        <p>{{ $t('placeholder.text') }}</p>
    </div>
</template>

<style scoped>
.placeholder {
    padding: 72px 32px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.ph-icon {
    width: 60px; height: 60px;
    border-radius: 14px;
    background: var(--surface-2);
    color: var(--ink-soft);
    display: flex;
    align-items: center;
    justify-content: center;
}
.placeholder h2 { font-size: 1.2rem; }
.placeholder p { color: var(--ink-soft); font-size: 0.92rem; max-width: 420px; }
</style>
