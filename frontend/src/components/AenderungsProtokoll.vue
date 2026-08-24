<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../api';
import UiCard from './ui/UiCard.vue';
import { FELDNAMEN } from '../labels.js';
import { datum } from '../format.js';

/**
 * Change log of a contact, a company or a deal. Loads its own data and,
 * lacking the privacy.view permission, simply renders nothing — neither
 * entries nor an error message. This lets every detail view embed the
 * same display without loading anything itself.
 */
const props = defineProps({
    subjectType: { type: String, required: true }, // contact | company | deal
    subjectId: { type: [Number, String], required: true },
});

const aenderungen = ref([]);

async function laden() {
    try {
        const { data } = await api.get('/change_logs', {
            params: { subjectType: props.subjectType, subjectId: props.subjectId },
        });
        aenderungen.value = data['hydra:member'] ?? data.member ?? [];
    } catch (e) {
        // Without the privacy.view permission the API returns 403 — in that
        // case just show no log instead of an error message.
        aenderungen.value = [];
    }
}
onMounted(laden);
watch(() => props.subjectId, laden);
</script>

<template>
    <UiCard v-if="aenderungen.length">
        <p class="t-caption">Änderungen</p>
        <div class="stack tight">
            <p v-for="c in aenderungen.slice(0, 8)" :key="c.id" class="t-footnote aenderung">
                <span class="feldname">{{ FELDNAMEN[c.field] || c.field }}</span>
                <span class="muted">{{ c.oldValue || '—' }} → {{ c.newValue || '—' }}</span>
                <span class="muted">{{ c.changedBy }}, {{ datum.format(new Date(c.changedAt)) }}</span>
            </p>
        </div>
    </UiCard>
</template>

<style scoped>
p { margin: 0; }
.t-caption { margin: 0 0 var(--sp-3); }
.aenderung { display: grid; gap: 1px; padding-bottom: var(--sp-2); border-bottom: 1px solid var(--separator); }
.aenderung:last-child { border-bottom: 0; padding-bottom: 0; }
.feldname { color: var(--label-primary); font-weight: 600; }
</style>
