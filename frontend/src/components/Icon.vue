<script setup>
// Reduziertes Strich-Icon-Set (1.75px, runde Enden). Bewusst kein
// Emoji und keine Icon-Library: wenige, ruhige Formen passen zur HIG.
defineProps({ name: { type: String, required: true }, size: { type: [Number, String], default: 20 } });

const paths = {
    overview:  'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z',
    contacts:  'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V21h14v-2.5C19 16 16 14 12 14Z',
    pipeline:  'M4 6h16M4 12h10M4 18h6',
    activity:  'M4 12h4l2.5 6L14 5l2.5 7H20',
    consent:   'M12 3 5 6v6c0 4 3 7 7 9 4-2 7-5 7-9V6l-7-3Zm-1 12-3-3 1.4-1.4L11 12.2l4.6-4.6L17 9l-6 6Z',
    settings:  'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.3 1a7 7 0 0 0-1.7-1L14.5 3h-4l-.4 2.4a7 7 0 0 0-1.7 1l-2.3-1-2 3.4 2 1.5a7 7 0 0 0 0 2l-2 1.5 2 3.4 2.3-1a7 7 0 0 0 1.7 1l.4 2.4h4l.4-2.4a7 7 0 0 0 1.7-1l2.3 1 2-3.4-2-1.5c.06-.33.1-.66.1-1Z',
    search:    'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm5-1.5L20 20',
    plus:      'M12 5v14M5 12h14',
    check:     'm5 12.5 4.5 4.5L19 7',
    close:     'M6 6l12 12M18 6 6 18',
    chevron:   'm9 5 7 7-7 7',
    sun:       'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4',
    moon:      'M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z',
    logout:    'M15 12H4m0 0 4-4m-4 4 4 4M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4',
    building:  'M5 21V5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v16M14 10h4a1 1 0 0 1 1 1v10M8 8h3M8 12h3M8 16h3M17 14h0M17 18h0',
    import:    'M12 3v11m0 0-4-4m4 4 4-4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3',
    mail:      'M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm0 0 8 5 8-5',
    globe:     'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3 12h18M12 3a9.4 9.4 0 0 1 7.1 14.5M12 3a9.4 9.4 0 0 0-7.1 14.5',
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name] || paths.overview" />
    </svg>
</template>
