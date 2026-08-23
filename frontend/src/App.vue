<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();
// Erscheinungsbild auch auf der Login-Seite anwenden (dort laeuft die Shell noch nicht).
onMounted(() => {
    document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'dark');
    if (auth.token && !auth.user) auth.loadMe().catch(() => auth.logout());
});
</script>

<template>
    <RouterView />
</template>
