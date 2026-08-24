<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();
// Apply the theme even on the login page, since the app shell isn't mounted there yet.
onMounted(() => {
    document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'dark');
    if (auth.token && !auth.user) auth.loadMe().catch(() => auth.logout());
});
</script>

<template>
    <RouterView />
</template>
