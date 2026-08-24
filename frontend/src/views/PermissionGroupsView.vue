<script setup>
import { onMounted, ref } from 'vue';
import api from '../api';
import { nachricht } from '../composables/nachricht.js';
import { useVerwaltungsBlatt } from '../composables/useVerwaltungsBlatt.js';
import { useLoeschenBestaetigung } from '../composables/useLoeschenBestaetigung.js';
import Icon from '../components/Icon.vue';
import UiButton from '../components/ui/UiButton.vue';
import UiCard from '../components/ui/UiCard.vue';
import UiField from '../components/ui/UiField.vue';
import UiBadge from '../components/ui/UiBadge.vue';
import UiSheet from '../components/ui/UiSheet.vue';

const gruppen = ref([]);
const bereiche = ref([]);
const stufenNamen = ref({});
const laedt = ref(true);
const fehler = ref('');

async function laden() {
    laedt.value = true;
    try {
        const [g, p] = await Promise.all([
            api.get('/permission_groups'),
            api.get('/permissions'),
        ]);
        gruppen.value = g.data['hydra:member'] ?? g.data.member ?? [];
        bereiche.value = p.data.bereiche ?? [];
        stufenNamen.value = p.data.stufenNamen ?? {};
        fehler.value = '';
    } catch (e) {
        fehler.value = e?.response?.status === 403
            ? 'Berechtigungsgruppen sind Administratoren vorbehalten.'
            : 'Die Berechtigungsgruppen konnten nicht geladen werden.';
    } finally {
        laedt.value = false;
    }
}
onMounted(laden);

/* ----------------------------------------------------------- Anzeige-Hilfen */

function bereichName(schluessel) {
    return bereiche.value.find((b) => b.schluessel === schluessel)?.name ?? schluessel;
}
function stufenTextVon(stufenWerte) {
    return Object.keys(stufenWerte || {})
        .filter((s) => stufenWerte[s])
        .map((s) => stufenNamen.value[s] ?? s)
        .join(', ');
}

/* ------------------------------------------------------------------ Anlegen/Bearbeiten */

// Leeres Rechte-Geruest je Bereich — nur die Stufen, die der Bereich laut API
// tatsaechlich kennt. Ein Schalter fuer eine Stufe, die es fuer den Bereich
// gar nicht gibt, waere ohne Wirkung und damit eine Luege in der Oberflaeche.
function leeresRechteGeruest() {
    const geruest = {};
    for (const b of bereiche.value) {
        geruest[b.schluessel] = {};
        for (const s of b.stufen) geruest[b.schluessel][s] = false;
    }
    return geruest;
}

// Vorhandene Gruppenrechte auf das Geruest legen — unbekannte Bereiche/Stufen
// (z. B. aus einer aelteren API-Version) werden dabei stillschweigend verworfen.
function entwurfRechteAus(gruppenRechte) {
    const geruest = leeresRechteGeruest();
    for (const [bereich, stufen] of Object.entries(gruppenRechte || {})) {
        if (!geruest[bereich]) continue;
        for (const [stufe, wert] of Object.entries(stufen || {})) {
            if (wert && stufe in geruest[bereich]) geruest[bereich][stufe] = true;
        }
    }
    return geruest;
}

// Nur gesetzte Stufen werden verschickt — die API verwirft "false" ohnehin,
// aber so bleibt die Nutzlast schon hier lesbar und minimal.
function baueRechte(entwurf) {
    const ergebnis = {};
    for (const b of bereiche.value) {
        const stufen = {};
        for (const s of b.stufen) {
            if (entwurf.rechte[b.schluessel]?.[s]) stufen[s] = true;
        }
        if (Object.keys(stufen).length) ergebnis[b.schluessel] = stufen;
    }
    return ergebnis;
}

const gruppeBlatt = useVerwaltungsBlatt('/permission_groups', {
    leererEntwurf: () => ({ name: '', rechte: leeresRechteGeruest() }),
    patchDaten: (entwurf) => ({ name: entwurf.name, rechte: baueRechte(entwurf) }),
    postDaten: (entwurf) => ({ name: entwurf.name, rechte: baueRechte(entwurf) }),
    nachSpeichern: laden,
});

function bearbeiten(gruppe) {
    gruppeBlatt.bearbeiten(gruppe.id, { name: gruppe.name, rechte: entwurfRechteAus(gruppe.rechte) });
}

// Schreiben ohne Lesen ergibt keinen Sinn — das Backend behandelt "schreiben"
// bereits so, als waere "lesen" mitgesetzt. Hier nur sichtbar gemacht: Lesen
// wird beim Setzen von Schreiben mit angehakt, und beim Entfernen von Lesen
// faellt Schreiben automatisch mit weg. Keine weitere Regel erfunden (z. B.
// fuer "loeschen") — die kennt das Backend nicht.
function stufeUmschalten(bereichSchluessel, stufe, aktiv) {
    const stufen = gruppeBlatt.entwurf.rechte[bereichSchluessel];
    stufen[stufe] = aktiv;
    if (stufe === 'schreiben' && aktiv) stufen.lesen = true;
    if (stufe === 'lesen' && !aktiv) stufen.schreiben = false;
}

const gruppeLoeschen = useLoeschenBestaetigung('/permission_groups', {
    holeId: (g) => g.id,
    nachLoeschen: laden,
});
</script>

<template>
    <div class="stack">
        <header class="head">
            <div>
                <h2 class="t-large-title">Berechtigungsgruppen</h2>
                <p class="t-subhead">Frei benannte Gruppen mit Lesen/Schreiben/Löschen je Bereich — z. B. für Praktikanten.</p>
            </div>
            <UiButton variant="primary" @click="gruppeBlatt.neu()">
                <Icon name="plus" :size="16" /> Gruppe anlegen
            </UiButton>
        </header>

        <UiCard class="hinweis">
            <p class="t-footnote muted">
                Ist einem Benutzer eine Gruppe zugewiesen, gilt <strong>ausschließlich</strong> sie — seine einzelnen
                Häkchen im Benutzerformular werden dann ignoriert. Ohne zugewiesene Gruppe gilt weiterhin die alte
                Häkchenliste.
            </p>
        </UiCard>

        <p v-if="fehler" class="t-footnote fehler">{{ fehler }}</p>

        <UiCard v-if="!laedt && !gruppen.length" class="leer">
            <p class="t-headline">Noch keine Berechtigungsgruppe</p>
            <p class="t-subhead">Lege eine Gruppe an, um Rechte gebündelt und wiederverwendbar zu vergeben.</p>
        </UiCard>

        <UiCard v-for="g in gruppen" :key="g.id" class="gruppe">
            <div class="gruppe__kopf">
                <p class="t-headline">{{ g.name }}</p>
                <div class="spacer" />
                <UiButton size="sm" @click="bearbeiten(g)">Bearbeiten</UiButton>
                <UiButton size="sm" variant="danger" @click="gruppeLoeschen.fragen(g)">Löschen</UiButton>
            </div>

            <div v-if="!Object.keys(g.rechte || {}).length" class="leer leer--gruppe">
                <p class="t-subhead">Keine Berechtigungen vergeben.</p>
            </div>
            <div v-else class="badges">
                <UiBadge v-for="(stufen, bereich) in g.rechte" :key="bereich" tone="quiet">
                    {{ bereichName(bereich) }}: {{ stufenTextVon(stufen) }}
                </UiBadge>
            </div>
        </UiCard>

        <!-- Gruppe anlegen / bearbeiten -->
        <UiSheet :offen="gruppeBlatt.offen"
                 :titel="gruppeBlatt.bearbeiteId ? 'Gruppe bearbeiten' : 'Gruppe anlegen'"
                 bestaetigen="Speichern" :laeuft="gruppeBlatt.speichert"
                 @schliessen="gruppeBlatt.offen = false" @bestaetigen="gruppeBlatt.speichern">
            <UiField v-model="gruppeBlatt.entwurf.name" label="Name" placeholder="z. B. Praktikant" />

            <div class="bereiche">
                <div v-for="b in bereiche" :key="b.schluessel" class="bereich">
                    <p class="t-caption">{{ b.name }}</p>
                    <label v-for="s in b.stufen" :key="s" class="haken">
                        <input type="checkbox"
                               :checked="gruppeBlatt.entwurf.rechte[b.schluessel]?.[s] ?? false"
                               @change="stufeUmschalten(b.schluessel, s, $event.target.checked)" />
                        <span>{{ stufenNamen[s] ?? s }}</span>
                    </label>
                </div>
            </div>

            <p v-if="gruppeBlatt.fehler" class="t-footnote fehler">{{ gruppeBlatt.fehler }}</p>
        </UiSheet>

        <!-- Gruppe löschen -->
        <UiSheet :offen="!!gruppeLoeschen.zumLoeschen" titel="Gruppe löschen"
                 :text="gruppeLoeschen.zumLoeschen ? `„${gruppeLoeschen.zumLoeschen.name}“ wird endgültig gelöscht. Benutzer mit dieser Gruppe fallen danach auf ihre einzelnen Häkchen zurück. Das lässt sich nicht rückgängig machen.` : ''"
                 bestaetigen="Löschen" ton="danger" :laeuft="gruppeLoeschen.loescht"
                 @schliessen="gruppeLoeschen.zumLoeschen = null" @bestaetigen="gruppeLoeschen.bestaetigen">
            <p v-if="gruppeLoeschen.fehler" class="t-footnote fehler">{{ gruppeLoeschen.fehler }}</p>
        </UiSheet>
    </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); flex-wrap: wrap; }
.head p { margin: var(--sp-1) 0 0; }
.hinweis { background: var(--accent-quiet); border-color: transparent; }
.hinweis p { margin: 0; }
.leer { text-align: center; padding: var(--sp-10); }
.leer p { margin: 0 0 var(--sp-2); }
.leer--gruppe { padding: var(--sp-5); }
.leer--gruppe p { margin: 0; }

.gruppe { display: flex; flex-direction: column; gap: var(--sp-3); }
.gruppe__kopf { display: flex; align-items: center; gap: var(--sp-3); flex-wrap: wrap; }
.gruppe__kopf p { margin: 0; }

.badges { display: flex; flex-wrap: wrap; gap: var(--sp-2); }

.bereiche { display: flex; flex-direction: column; gap: var(--sp-4); }
.bereich .t-caption { margin: 0 0 var(--sp-1); }

.haken { display: flex; align-items: center; gap: var(--sp-3); font-size: var(--text-subhead); min-height: 40px; cursor: pointer; }
.haken input { width: 20px; height: 20px; accent-color: var(--accent); flex: none; }

@media (max-width: 700px) {
    .head :deep(.btn) { width: 100%; min-height: 46px; }
    .gruppe__kopf { align-items: stretch; }
    .gruppe__kopf :deep(.btn) { min-height: 44px; }
}
</style>
