import { reactive, ref } from 'vue';
import api from '../api';
import { nachricht } from './nachricht.js';

/**
 * Shared logic for a create/edit sheet: sheet state, draft, and saving
 * (PATCH when editing, POST when creating). Consolidates the block that
 * was practically duplicated 1:1 between pipeline and stage.
 *
 * @param {string} endpoint API path prefix, e.g. '/pipelines' or '/stages'.
 * @param {object} optionen
 * @param {(kontext: any) => object} optionen.leererEntwurf
 *        Returns the empty draft for "new" (kontext optional, e.g. unused).
 * @param {(entwurf: object) => object} optionen.patchDaten
 *        Builds the PATCH payload for editing.
 * @param {(entwurf: object, kontext: any) => object} optionen.postDaten
 *        Builds the POST payload for creating.
 * @param {() => Promise<void>} optionen.nachSpeichern
 *        Called after a successful save (e.g. laden()).
 */
export function useVerwaltungsBlatt(endpoint, { leererEntwurf, patchDaten, postDaten, nachSpeichern }) {
    const offen = ref(false);
    const bearbeiteId = ref(null);
    const entwurf = ref(leererEntwurf());
    const kontext = ref(null);
    const speichert = ref(false);
    const fehler = ref('');

    function neu(kontextWert = null) {
        bearbeiteId.value = null;
        entwurf.value = leererEntwurf(kontextWert);
        kontext.value = kontextWert;
        fehler.value = '';
        offen.value = true;
    }
    function bearbeiten(id, entwurfWerte, kontextWert = null) {
        bearbeiteId.value = id;
        entwurf.value = entwurfWerte;
        kontext.value = kontextWert;
        fehler.value = '';
        offen.value = true;
    }
    async function speichern() {
        speichert.value = true;
        fehler.value = '';
        try {
            if (bearbeiteId.value) {
                await api.patch(`${endpoint}/${bearbeiteId.value}`, patchDaten(entwurf.value), {
                    headers: { 'Content-Type': 'application/merge-patch+json' },
                });
            } else {
                await api.post(endpoint, postDaten(entwurf.value, kontext.value), {
                    headers: { 'Content-Type': 'application/ld+json' },
                });
            }
            offen.value = false;
            await nachSpeichern();
        } catch (e) {
            fehler.value = nachricht(e, 'Speichern hat nicht geklappt.');
        } finally {
            speichert.value = false;
        }
    }

    // reactive() instead of a plain object of refs, so that e.g. "pipelineBlatt.offen"
    // is auto-unwrapped in the template — nested refs are only read/written
    // without ".value" when the containing object is itself reactive.
    return reactive({ offen, bearbeiteId, entwurf, kontext, speichert, fehler, neu, bearbeiten, speichern });
}
