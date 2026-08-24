// Raw technical field names from the change log are meaningless to users —
// this covers contact, company and deal (see api/src/Entity/Contact.php,
// Company.php, Deal.php). Unknown field names fall back to the raw value
// in the display.
export const FELDNAMEN = {
    // Contact
    firstName: 'Vorname', lastName: 'Nachname', email: 'E-Mail', phone: 'Telefon',
    position: 'Position', department: 'Abteilung', status: 'Status', source: 'Herkunft',
    company: 'Firma', notes: 'Notizen', primaryContact: 'Hauptkontakt',
    consentGivenAt: 'Einwilligung erteilt', consentWithdrawnAt: 'Einwilligung widerrufen',
    deleteAfter: 'Löschvormerkung',
    // Company
    name: 'Name', street: 'Straße', zipCode: 'PLZ', city: 'Ort', website: 'Website',
    // Deal
    title: 'Titel', value: 'Wert', currency: 'Währung', stage: 'Phase',
    contact: 'Kontakt', owner: 'Verantwortlich', expectedCloseAt: 'Erwarteter Abschluss',
    lostReason: 'Verlustgrund',
};

export const ART = {
    anruf: 'Anruf',
    notiz: 'Notiz',
    aufgabe: 'Aufgabe',
    email: 'E-Mail',
    termin: 'Termin',
};

export const STATUS = {
    neu: 'Neu',
    in_kontakt: 'In Kontakt',
    qualifiziert: 'Qualifiziert',
    kunde: 'Kunde',
    kein_interesse: 'Kein Interesse',
};

export const STATUS_LABEL = {
    neu: 'Neu',
    in_kontakt: 'In Kontakt',
    qualifiziert: 'Qualifiziert',
    kunde: 'Kunde',
    kein_interesse: 'Kein Interesse',
};

export const QUELLE = {
    formular: 'Formular',
    telefon: 'Telefon',
    messe: 'Messe',
    empfehlung: 'Empfehlung',
    eigene_recherche: 'Recherche',
    import: 'Import',
    sonstiges: 'Sonstiges',
};

export const QUELLE_LABEL = {
    formular: 'Formular',
    telefon: 'Telefon',
    messe: 'Messe',
    empfehlung: 'Empfehlung',
    eigene_recherche: 'Recherche',
    import: 'Import',
    sonstiges: 'Sonstiges',
};

export const SICHERHEIT_LABEL = {
    sicher: 'Sicher',
    moeglich: 'Möglich',
};

// Type of a pipeline stage (Stage.art) — decides whether a deal in it
// counts as open, won or lost. The stage names themselves come from the
// database now, not from this file.
export const STAGE_ART = {
    offen: 'Offen',
    gewonnen: 'Gewonnen',
    verloren: 'Verloren',
};

export const STAGE_ART_HINWEIS = {
    offen: 'Ein Vorgang in dieser Phase zählt in der Auswertung als offen.',
    gewonnen: 'Ein Vorgang in dieser Phase zählt als gewonnen — unabhängig davon, wie die Phase heißt.',
    verloren: 'Ein Vorgang in dieser Phase zählt als verloren — unabhängig davon, wie die Phase heißt.',
};
