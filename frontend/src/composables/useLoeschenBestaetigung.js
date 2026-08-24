import { reactive, ref } from 'vue';
import api from '../api';
import { nachricht } from './nachricht.js';

/**
 * Shared logic for a delete-confirmation sheet: remembers the object to
 * delete, deletes it via DELETE, and shows an error message directly in
 * the sheet — in particular the 409 case, when deals still hang off the
 * stage or pipeline. Consolidates the block that was practically
 * duplicated 1:1 between pipeline and stage.
 *
 * @param {string} endpoint API path prefix, e.g. '/pipelines' or '/stages'.
 * @param {object} optionen
 * @param {(zumLoeschen: any) => string|number} optionen.holeId
 *        Reads the ID for the DELETE call from the remembered object.
 * @param {() => Promise<void>} optionen.nachLoeschen
 *        Called after a successful delete (e.g. laden()).
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
            // If deals still hang off the stage or pipeline, the API responds
            // with 409 and a readable message in the "detail" field — we show
            // that directly in the sheet instead of just closing it silently.
            fehler.value = nachricht(e, 'Löschen hat nicht geklappt.');
        } finally {
            loescht.value = false;
        }
    }

    // reactive() instead of a plain object of refs, so that e.g. "pipelineLoeschen.zumLoeschen"
    // is auto-unwrapped in the template (same reasoning as in useVerwaltungsBlatt.js).
    return reactive({ zumLoeschen, loescht, fehler, fragen, bestaetigen });
}
