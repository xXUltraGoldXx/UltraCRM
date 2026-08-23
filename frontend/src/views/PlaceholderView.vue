<script setup>
import UiCard from '../components/ui/UiCard.vue';
defineProps({ titel: String, text: String });
</script>

<template>
    <div class="stack">
        <h2 class="t-large-title">{{ titel }}</h2>
        <UiCard>
            <p class="t-body">{{ text }}</p>
        </UiCard>
    </div>
</template>
