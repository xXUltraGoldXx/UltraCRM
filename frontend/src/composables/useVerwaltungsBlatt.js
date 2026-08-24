import { reactive, ref } from 'vue';
import api from '../api';
import { nachricht } from './nachricht.js';

/**
 * Gemeinsame Logik fuer ein Anlegen/Bearbeiten-Blatt: Blatt-Status, Entwurf
 * und Speichern (PATCH beim Bearbeiten, POST beim Anlegen). Fasst den Block
 * zusammen, der bei Pipeline und Phase praktisch 1:1 dupliziert war.
 *
 * @param {string} endpoint API-Pfad-Praefix, z. B. '/pipelines' oder '/stages'.
 * @param {object} optionen
 * @param {(kontext: any) => object} optionen.leererEntwurf
 *        Liefert den leeren Entwurf fuer "neu" (kontext optional, z. B. ungenutzt).
 * @param {(entwurf: object) => object} optionen.patchDaten
 *        Baut die Nutzlast fuer PATCH beim Bearbeiten.
 * @param {(entwurf: object, kontext: any) => object} optionen.postDaten
 *        Baut die Nutzlast fuer POST beim Anlegen.
 * @param {() => Promise<void>} optionen.nachSpeichern
 *        Wird nach erfolgreichem Speichern aufgerufen (z. B. laden()).
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

    // reactive() statt eines rohen Objekts aus Refs, damit z. B. "pipelineBlatt.offen"
    // im Template automatisch entpackt wird — verschachtelte Refs werden nur dann
    // ohne ".value" gelesen/geschrieben, wenn das umschliessende Objekt reaktiv ist.
    return reactive({ offen, bearbeiteId, entwurf, kontext, speichert, fehler, neu, bearbeiten, speichern });
}
