<template>
    <section class="card"><slot /></section>
</template>

<style scoped>
.card {
    background: var(--bg-elevated);
    border: 1px solid var(--separator);
    border-radius: var(--radius-l);
    padding: var(--sp-5);
}
</style>
