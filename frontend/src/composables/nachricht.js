/**
 * Reads an API error message from the response (RFC7807 field "detail" or
 * the Hydra field "hydra:description") and otherwise falls back to a
 * default message. Used by the management composables and directly in
 * views that handle their own error cases (e.g. the reorder swap).
 */
export function nachricht(e, standard) {
    return e?.response?.data?.detail
        || e?.response?.data?.['hydra:description']
        || standard;
}
