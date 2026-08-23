// Zentraler Katalog der Modul-Rechte. Wird im Benutzer-Formular als Checkboxen
// angezeigt und steuert im Frontend, welche Bereiche ein Mitarbeiter sieht.
// Administratoren haben immer alle Rechte.
//
// Modul #11 Mehrsprachigkeit: moduleKey/labelKey statt fester Strings -- diese
// Datei ist reines JS, UserForm.vue uebersetzt ueber $t(...) beim Rendern.
export const PERMISSION_GROUPS = [
    {
        moduleKey: 'permissions.groupCustomers',
        items: [
            { key: 'customers.view', labelKey: 'permissions.customersView' },
            { key: 'customers.manage', labelKey: 'permissions.customersManage' },
        ],
    },
    {
        moduleKey: 'permissions.groupForms',
        items: [
            { key: 'submissions.view', labelKey: 'permissions.submissionsView' },
            { key: 'submissions.manage', labelKey: 'permissions.submissionsManage' },
            { key: 'templates.manage', labelKey: 'permissions.templatesManage' },
        ],
    },
    {
        moduleKey: 'permissions.groupOther',
        items: [
            { key: 'calendar.view', labelKey: 'permissions.calendarView' },
            { key: 'calendar.manage', labelKey: 'permissions.calendarManage' },
            { key: 'holiday.view', labelKey: 'permissions.holidayView' },
            { key: 'holiday.manage', labelKey: 'permissions.holidayManage' },
        ],
    },
];

// Welche Rechte gibt ein Menüpunkt frei (mind. eines davon nötig)
export const NAV_PERMISSIONS = {
    customers: ['customers.view', 'customers.manage'],
    submissions: ['submissions.view', 'submissions.manage'],
    templates: ['templates.manage'],
    calendar: ['calendar.view'],
    holiday: ['holiday.view', 'holiday.manage'],
};

export const ALL_PERMISSION_KEYS = PERMISSION_GROUPS.flatMap((g) => g.items.map((i) => i.key));
