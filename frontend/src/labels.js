// Technische Feldnamen aus dem Änderungsprotokoll sind für Anwender
// nichtssagend — deckt Kontakt, Firma und Vorgang ab (siehe api/src/Entity/
// Contact.php, Company.php, Deal.php). Unbekannte Feldnamen fallen in der
// Anzeige auf den Rohwert zurück.
export const FELDNAMEN = {
    // Kontakt
    firstName: 'Vorname', lastName: 'Nachname', email: 'E-Mail', phone: 'Telefon',
    position: 'Position', department: 'Abteilung', status: 'Status', source: 'Herkunft',
    company: 'Firma', notes: 'Notizen', primaryContact: 'Hauptkontakt',
    consentGivenAt: 'Einwilligung erteilt', consentWithdrawnAt: 'Einwilligung widerrufen',
    deleteAfter: 'Löschvormerkung',
    // Firma
    name: 'Name', street: 'Straße', zipCode: 'PLZ', city: 'Ort', website: 'Website',
    // Vorgang
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

// Art einer Pipeline-Phase (Stage.art) — entscheidet, ob ein Vorgang darin
// als offen, gewonnen oder verloren zaehlt. Die Namen der Phasen selbst
// kommen seit A5 aus der Datenbank und stehen nicht mehr hier.
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
