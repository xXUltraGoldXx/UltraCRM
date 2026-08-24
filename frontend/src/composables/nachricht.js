/**
 * Liest eine API-Fehlermeldung aus der Antwort (RFC7807-Feld "detail" bzw.
 * Hydra-Feld "hydra:description") und faellt sonst auf eine Standardmeldung
 * zurueck. Wird von den Verwaltungs-Composables und direkt in Views genutzt,
 * die eigene Fehlerfaelle behandeln (z. B. der Reihenfolge-Tausch).
 */
export function nachricht(e, standard) {
    return e?.response?.data?.detail
        || e?.response?.data?.['hydra:description']
        || standard;
}
