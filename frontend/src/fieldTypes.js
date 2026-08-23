// Zentrale Definition aller Feld-Typen für Designer + Renderer + PDF
//
// Modul #11 Mehrsprachigkeit: labelKey statt festem label -- diese Datei ist
// reines JS (keine Vue-Komponente), deshalb kein useI18n() hier moeglich.
// Aufrufer (TemplateDesignerView.vue) uebersetzen ueber $t(ft.labelKey).
// defaults.label bleibt bewusst Deutsch: das ist der INITIALE Wert eines neu
// gezogenen Feldes, wird sofort Teil des vom Nutzer editierten/gespeicherten
// Formular-Schemas (persistierter Inhalt, keine Interface-Chrome) -- analog
// dazu, dass Server-Fehlertexte laut Auftrag ebenfalls nicht uebersetzt werden.
export const FIELD_TYPES = [
    { type: 'heading', labelKey: 'fieldTypes.heading', icon: 'heading', group: 'layout', hint: 'Trennt Abschnitte', defaults: { label: 'Abschnitt' } },
    { type: 'text', labelKey: 'fieldTypes.text', icon: 'text', group: 'basis', hint: 'Kurze Eingabe', defaults: { label: 'Textfeld', placeholder: '' } },
    { type: 'textarea', labelKey: 'fieldTypes.textarea', icon: 'textarea', group: 'basis', hint: 'Längerer Text', defaults: { label: 'Beschreibung', placeholder: '' } },
    { type: 'number', labelKey: 'fieldTypes.number', icon: 'number', group: 'basis', hint: 'Nur Ziffern', defaults: { label: 'Zahl', placeholder: '' } },
    { type: 'date', labelKey: 'fieldTypes.date', icon: 'calendar', group: 'basis', hint: '', defaults: { label: 'Datum' } },
    { type: 'time', labelKey: 'fieldTypes.time', icon: 'clock', group: 'basis', hint: '', defaults: { label: 'Uhrzeit' } },
    { type: 'select', labelKey: 'fieldTypes.select', icon: 'select', group: 'basis', hint: 'Liste zum Auswählen', defaults: { label: 'Auswahl', options: ['Option 1', 'Option 2'] } },
    { type: 'checkbox', labelKey: 'fieldTypes.checkbox', icon: 'checkbox', group: 'basis', hint: 'Ja/Nein', defaults: { label: 'Bestätigung' } },
    { type: 'customer', labelKey: 'fieldTypes.customer', icon: 'user', group: 'speziell', hint: 'Verknüpft einen Kunden', defaults: { label: 'Kunde' } },
    { type: 'spareparts', labelKey: 'fieldTypes.spareparts', icon: 'tool', group: 'speziell', hint: 'Zeilen: Name, Alt-SN, Neu-SN', defaults: { label: 'Verbaute Ersatzteile' } },
    { type: 'signature', labelKey: 'fieldTypes.signature', icon: 'signature', group: 'speziell', hint: 'Feld zum Unterschreiben', defaults: { label: 'Unterschrift' } },
];

export function fieldMeta(type) {
    return FIELD_TYPES.find((f) => f.type === type) || { icon: 'file', labelKey: null };
}

export function newField(type) {
    const def = FIELD_TYPES.find((f) => f.type === type);
    const base = {
        id: 'f' + Math.random().toString(36).slice(2, 9),
        type,
        label: '',
        placeholder: '',
        required: false,
        width: 'full',
        options: [],
    };
    return { ...base, ...(def?.defaults || {}) };
}

// Icon-Auswahl für Vorlagen (statt Emoji)
export const TEMPLATE_ICONS = ['file', 'clipboard', 'tool', 'printer', 'package', 'truck', 'settings', 'checkbox'];

// Farb-Optionen für Vorlagen
export const TEMPLATE_COLORS = ['blue', 'green', 'purple', 'orange', 'red', 'teal'];
export const COLOR_HEX = {
    blue: '#3b82f6',
    green: '#22c55e',
    purple: '#8b5cf6',
    orange: '#f59e0b',
    red: '#ef4444',
    teal: '#14b8a6',
};
