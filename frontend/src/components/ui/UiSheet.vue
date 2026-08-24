<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import UiButton from './UiButton.vue';

/**
 * A sheet that slides up from the bottom on mobile and sits centered on
 * desktop — the native pattern for both. Replaces window.prompt and
 * window.confirm: custom labels, keyboard handling, and actually usable
 * on mobile.
 */
const props = defineProps({
    offen: Boolean,
    titel: String,
    text: String,
    bestaetigen: { type: String, default: 'Bestätigen' },
    ton: { type: String, default: 'primary' }, // primary | danger
    laeuft: Boolean,
    /**
     * Without confirm/cancel actions — for sheets that only show content
     * (e.g. a menu). This used to be duplicated as a second implementation
     * in AppShell.vue, with the same shell, the same grip handle and the
     * same transition timings.
     */
    ohneAktionen: Boolean,
});
const emit = defineEmits(['schliessen', 'bestaetigen']);

function beiTaste(e) {
    if (e.key === 'Escape' && props.offen) emit('schliessen');
}
onMounted(() => document.addEventListener('keydown', beiTaste));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', beiTaste);
    document.body.style.overflow = '';
});
// Keep the background from scrolling while the sheet is open.
watch(() => props.offen, (o) => { document.body.style.overflow = o ? 'hidden' : ''; });
</script>

<template>
    <Teleport to="body">
        <Transition name="blatt">
            <div v-if="offen" class="huelle" role="dialog" aria-modal="true" @click.self="emit('schliessen')">
                <div class="blatt">
                    <div class="griff" />
                    <h3 v-if="titel" class="t-title-3">{{ titel }}</h3>
                    <p v-if="text" class="t-subhead">{{ text }}</p>

                    <div class="inhalt"><slot /></div>

                    <div v-if="!ohneAktionen" class="aktionen">
                        <UiButton @click="emit('schliessen')">Abbrechen</UiButton>
                        <UiButton :variant="ton" :disabled="laeuft" @click="emit('bestaetigen')">
                            {{ laeuft ? 'Einen Moment…' : bestaetigen }}
                        </UiButton>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.huelle {
    position: fixed; inset: 0; z-index: 100;
    display: flex; align-items: center; justify-content: center;
    background: var(--overlay-scrim);
    backdrop-filter: blur(3px);
    padding: var(--sp-4);
}
.blatt {
    width: min(460px, 100%);
    display: flex; flex-direction: column; gap: var(--sp-3);
    background: var(--bg-elevated);
    border: 1px solid var(--separator);
    border-radius: var(--radius-xl);
    padding: var(--sp-6);
    box-shadow: var(--shadow-card);
    max-height: 88vh; overflow-y: auto;
}
.griff { display: none; }
.blatt h3, .blatt p { margin: 0; }
.inhalt:empty { display: none; }
.inhalt { display: flex; flex-direction: column; gap: var(--sp-4); margin-top: var(--sp-2); }
.aktionen { display: flex; gap: var(--sp-2); justify-content: flex-end; margin-top: var(--sp-2); }
.aktionen :deep(.btn) { min-height: 44px; }

.blatt-enter-active, .blatt-leave-active { transition: opacity .2s ease; }
.blatt-enter-active .blatt, .blatt-leave-active .blatt { transition: transform .24s cubic-bezier(.32,.72,0,1); }
.blatt-enter-from, .blatt-leave-to { opacity: 0; }

/* Mobile: slide in from the bottom, full width, thumb reaches the buttons. */
@media (max-width: 700px) {
    .huelle { align-items: flex-end; padding: 0; }
    .blatt {
        width: 100%;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        padding: var(--sp-3) var(--sp-5) calc(var(--sp-6) + env(safe-area-inset-bottom));
        max-height: 92vh;
    }
    .griff {
        display: block; width: 38px; height: 5px;
        border-radius: var(--radius-pill);
        background: var(--fill-tertiary);
        margin: 0 auto var(--sp-3);
    }
    .aktionen { flex-direction: column-reverse; }
    .aktionen :deep(.btn) { width: 100%; }
    .blatt-enter-from .blatt, .blatt-leave-to .blatt { transform: translateY(100%); }
}
</style>
