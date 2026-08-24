import { reactive, ref } from 'vue';
import api from '../api';
import { nachricht } from './nachricht.js';

/**
 * Gemeinsame Logik fuer ein Loeschen-Bestaetigungsblatt: merkt sich das zu
 * loeschende Objekt, loescht per DELETE und zeigt eine Fehlermeldung direkt
 * im Blatt an — insbesondere den 409-Fall, wenn noch Vorgaenge an der Phase
 * oder Pipeline haengen. Fasst den Block zusammen, der bei Pipeline und
 * Phase praktisch 1:1 dupliziert war.
 *
 * @param {string} endpoint API-Pfad-Praefix, z. B. '/pipelines' oder '/stages'.
 * @param {object} optionen
 * @param {(zumLoeschen: any) => string|number} optionen.holeId
 *        Liest die ID fuer den DELETE-Aufruf aus dem gemerkten Objekt.
 * @param {() => Promise<void>} optionen.nachLoeschen
 *        Wird nach erfolgreichem Loeschen aufgerufen (z. B. laden()).
 */
export function useLoeschenBestaetigung(endpoint, { holeId, nachLoeschen }) {
    const zumLoeschen = ref(null);
    const loescht = ref(false);
    const fehler = ref('');

    function fragen(wert) {
        zumLoeschen.value = wert;
        fehler.value = '';
    }
    async function bestaetigen() {
        loescht.value = true;
        fehler.value = '';
        try {
            await api.delete(`${endpoint}/${holeId(zumLoeschen.value)}`);
            zumLoeschen.value = null;
            await nachLoeschen();
        } catch (e) {
            // Haengen noch Vorgaenge an der Phase oder Pipeline, antwortet die
            // API mit 409 und einer verstaendlichen Meldung im Feld "detail" —
            // die zeigen wir hier direkt im Blatt, statt es kommentarlos zu schliessen.
            fehler.value = nachricht(e, 'Löschen hat nicht geklappt.');
        } finally {
            loescht.value = false;
        }
    }

    // reactive() statt eines rohen Objekts aus Refs, damit z. B. "pipelineLoeschen.zumLoeschen"
    // im Template automatisch entpackt wird (siehe useVerwaltungsBlatt.js).
    return reactive({ zumLoeschen, loescht, fehler, fragen, bestaetigen });
}
