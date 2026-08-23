<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { bcp47Locale } from '../i18n';
import Icon from '../components/Icon.vue';
import CalendarMonthGrid from '../components/CalendarMonthGrid.vue';
import CalendarWeekGrid from '../components/CalendarWeekGrid.vue';
import AppointmentModal from '../components/AppointmentModal.vue';

const auth = useAuthStore();
const canManage = computed(() => auth.can('calendar.manage'));
const { t, locale } = useI18n();

const viewMode = ref('month');   // 'month' | 'week'
const anchor = ref(startOfToday());
const rawAppointments = ref([]);
const rawHolidays = ref([]);
const loading = ref(false);

// Modul #9: Namen fuer die Urlaubs-Kalendereintraege -- die Kalender-Route
// liefert requestedBy nur als IRI (siehe HolidayRequest::holiday:calendar-
// Gruppe), Aufloesung ueber dieselbe /users/picker-Liste wie im
// AppointmentModal-Mitarbeiter-Dropdown (Modul #8), kein Extra-Endpoint noetig.
const employeeNames = ref({}); // id (number) -> displayName
async function loadEmployeeNames() {
    try {
        const { data } = await api.get('/users/picker');
        const list = data['member'] || data['hydra:member'] || [];
        employeeNames.value = Object.fromEntries(list.map((u) => [u.id, u.displayName]));
    } catch { /* Tooltip zeigt dann nur "Urlaub" ohne Namen */ }
}

const showModal = ref(false);
const editing = ref(null);       // vorhandener Termin/HolidayRequest (raw) oder null
const modalDefaultDate = ref(null);
// Paket 1, Punkt 2 (Lese-Modal): editingKind/readonlyMode steuern, welcher
// Anzeige-Zweig in AppointmentModal.vue aktiv ist.
const editingKind = ref('appointment');   // 'appointment' | 'holiday'
const readonlyMode = ref(false);
const readonlyHolidayName = ref('');

function startOfToday() {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    return d;
}
function addDays(d, n) {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}
function mondayOf(d) {
    const dow = (d.getDay() + 6) % 7;
    return addDays(d, -dow);
}

// Sichtfenster je Modus -- 6x7-Monatsraster deckt auch Nachbarmonats-Tage ab
// (siehe CalendarMonthGrid::gridStart), damit Termine über die Monatsgrenze
// hinweg korrekt mitgeladen werden.
const windowRange = computed(() => {
    if (viewMode.value === 'week') {
        const from = mondayOf(anchor.value);
        return { from, to: addDays(from, 7) };
    }
    const first = new Date(anchor.value.getFullYear(), anchor.value.getMonth(), 1);
    const from = mondayOf(first);
    return { from, to: addDays(from, 42) };
});

// Einheitliche Item-Form fuer beide Quellen (Termine + Modul #9 Urlaub).
// KEIN eigenes type-Feld am Appointment-Entity -- "kind" existiert nur hier
// im Frontend-Mapping. id ist bewusst ein zusammengesetzter String
// (apt-.../hol-...), weil Appointment- und HolidayRequest-IDs unabhaengig
// voneinander bei 1 anfangen und sich sonst kollidieren wuerden (Vue-:key
// UND die byId-Map darunter).
function toCalendarItem(appt) {
    return {
        id: `apt-${appt.id}`,
        title: appt.title,
        startsAt: appt.startsAt,
        endsAt: appt.endsAt,
        allDay: appt.allDay,
        color: appt.color,
        kind: 'appointment',
    };
}

// Eigene Farbe (teal, aus der bestehenden Palette in fieldTypes.js) statt der
// Appointment-Standardfarbe -- optisch klar als Urlaub statt Termin erkennbar.
//
// WICHTIG endsAt-Konvention: HolidayRequest::endsAt ist der letzte Urlaubstag
// INKLUSIVE (so legt es HolidayRequestProcessor::calculateWeekdays an, "<= end",
// und so speichert HolidayRequestForm.vue es unveraendert). Die Grid-Komponenten
// (CalendarMonthGrid/CalendarWeekGrid) erwarten dagegen ein EXKLUSIVES endsAt,
// weil Appointment::endsAt so funktioniert (naechster-Mitternacht-Grenze) --
// ohne Anpassung wuerde der letzte Urlaubstag im Kalender fehlen (beobachtet
// beim manuellen Testdurchlauf). Deshalb hier +1 Tag beim Mapping in die
// gemeinsame CalendarItem-Form, NICHT an der Entity selbst (die serverseitige
// Werktage-Berechnung bleibt "inklusiv" und unveraendert korrekt).
function toHolidayItem(hr) {
    const employeeId = Number(String(hr.requestedBy || '').split('/').pop());
    const name = employeeNames.value[employeeId] || t('calendar.holidayItemFallbackName');
    const endExclusive = new Date(hr.endsAt);
    endExclusive.setDate(endExclusive.getDate() + 1);
    return {
        id: `hol-${hr.id}`,
        title: t('calendar.holidayItemTitle', { name }),
        startsAt: hr.startsAt,
        endsAt: endExclusive.toISOString(),
        allDay: true,
        color: 'teal',
        kind: 'holiday',
    };
}

const items = computed(() => [
    ...rawAppointments.value.map(toCalendarItem),
    ...rawHolidays.value.map(toHolidayItem),
]);
const byId = computed(() => new Map([
    ...rawAppointments.value.map((a) => [`apt-${a.id}`, a]),
    ...rawHolidays.value.map((h) => [`hol-${h.id}`, h]),
]));

async function load() {
    loading.value = true;
    try {
        const { from, to } = windowRange.value;
        const params = { from: from.toISOString(), to: to.toISOString(), itemsPerPage: 300 };
        // Beide Quellen parallel laden -- der Holiday-Fetch darf einen fehlenden
        // Termin-Fetch nicht blockieren und umgekehrt (Promise.allSettled statt .all).
        const [aptRes, holRes] = await Promise.allSettled([
            api.get('/appointments', { params }),
            api.get('/holiday_requests/calendar', { params: { from: params.from, to: params.to } }),
        ]);
        rawAppointments.value = aptRes.status === 'fulfilled'
            ? (aptRes.value.data['member'] || aptRes.value.data['hydra:member'] || []) : [];
        rawHolidays.value = holRes.status === 'fulfilled'
            ? (holRes.value.data['member'] || holRes.value.data['hydra:member'] || []) : [];
    } finally {
        loading.value = false;
    }
}

watch([viewMode, windowRange], load, { deep: false });
onMounted(() => { load(); loadEmployeeNames(); });

function goToday() { anchor.value = startOfToday(); }
function goPrev() { anchor.value = viewMode.value === 'week' ? addDays(anchor.value, -7) : new Date(anchor.value.getFullYear(), anchor.value.getMonth() - 1, 1); }
function goNext() { anchor.value = viewMode.value === 'week' ? addDays(anchor.value, 7) : new Date(anchor.value.getFullYear(), anchor.value.getMonth() + 1, 1); }

const rangeLabel = computed(() => {
    void locale.value; // Neuberechnung bei Sprachwechsel, siehe CalendarMonthGrid-Kommentar
    if (viewMode.value === 'week') {
        const from = mondayOf(anchor.value);
        const to = addDays(from, 6);
        const fmt = (d) => d.toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit' });
        return `${fmt(from)} – ${fmt(to)} ${to.getFullYear()}`;
    }
    return anchor.value.toLocaleDateString(bcp47Locale(), { month: 'long', year: 'numeric' });
});

function openNew(date) {
    if (!canManage.value) return;
    editing.value = null;
    modalDefaultDate.value = date || anchor.value;
    showModal.value = true;
}
function openEdit(item) {
    const full = byId.value.get(item.id);
    if (!full) return;

    if (item.kind === 'holiday') {
        // Paket 1, Punkt 2: Urlaubs-Items sind jetzt klickbar -- IMMER read-only
        // (HolidayRequest hat eine eigene Zustandsmaschine mit Genehmigungs-
        // Workflow, Entscheiden bleibt UrlaubView vorbehalten). Nur Name +
        // Zeitraum, wie beauftragt.
        const employeeId = Number(String(full.requestedBy || '').split('/').pop());
        readonlyHolidayName.value = employeeNames.value[employeeId] || t('calendar.holidayItemFallbackName');
        editingKind.value = 'holiday';
        editing.value = full;
        readonlyMode.value = true;
        modalDefaultDate.value = null;
        showModal.value = true;
        return;
    }

    // Termin: mit calendar.manage editierbar, sonst read-only (Punkt 2) --
    // vorher passierte hier bei fehlendem calendar.manage gar nichts.
    editingKind.value = 'appointment';
    editing.value = full;
    readonlyMode.value = !canManage.value;
    modalDefaultDate.value = null;
    showModal.value = true;
}
async function onSaved() {
    showModal.value = false;
    await load();
}
async function onDeleted() {
    showModal.value = false;
    await load();
}

// Paket 2, Punkt 2: Termin per Drag&Drop auf einen anderen Tag verschieben --
// Tages-Delta auf startsAt UND endsAt (Uhrzeiten und Mehrtages-Laenge bleiben
// unveraendert, ein reiner Kalendertage-Versatz). Optimistisches UI: Item
// sofort im lokalen State umhaengen, bei PATCH-Fehler zurueckrollen.
// CalendarMonthGrid emittiert move-item nur fuer kind=appointment und nur,
// wenn canManage=true war (siehe dortiger isDraggable-Check) -- hier trotzdem
// defensiv nochmal geprueft, falls sich das je aendert.
function startOfDay(d) {
    const r = new Date(d);
    r.setHours(0, 0, 0, 0);
    return r;
}
function daysBetween(a, b) {
    // Math.round statt einer exakten Division, damit ein DST-Wechsel
    // zwischen den beiden Mitternaechten (23h/25h-Tag) nicht zu einem
    // falschen Off-by-one fuehrt.
    return Math.round((startOfDay(b) - startOfDay(a)) / 86400000);
}

// In-Flight-Schutz (Opus-Review Paket 2): ein zweiter Drag desselben Termins
// vor der Antwort des ersten wuerde sonst einen veralteten Backup-Stand
// sichern und beim Rollback UI und Server auseinanderlaufen lassen
// ("der Termin springt manchmal zurueck"). Plain Set reicht, keine
// Reaktivitaet noetig -- reiner Guard.
const movingIds = new Set();

async function onMoveItem({ item, newDate }) {
    if (item.kind !== 'appointment' || !canManage.value) return;
    const full = byId.value.get(item.id);
    if (!full) return;
    if (movingIds.has(full.id)) return; // Drag laeuft schon -- zweiten verwerfen

    const oldStart = new Date(full.startsAt);
    const dayDelta = daysBetween(oldStart, newDate);
    if (dayDelta === 0) return; // auf demselben Tag abgelegt -- nichts zu tun

    const newStartsAt = addDays(oldStart, dayDelta);
    const newEndsAt = addDays(new Date(full.endsAt), dayDelta);

    const idx = rawAppointments.value.findIndex((a) => a.id === full.id);
    const backup = idx >= 0 ? { ...rawAppointments.value[idx] } : null;
    if (idx >= 0) {
        rawAppointments.value[idx] = {
            ...rawAppointments.value[idx],
            startsAt: newStartsAt.toISOString(),
            endsAt: newEndsAt.toISOString(),
        };
    }

    movingIds.add(full.id);
    try {
        await api.patch(`/appointments/${full.id}`, {
            startsAt: newStartsAt.toISOString(),
            endsAt: newEndsAt.toISOString(),
        }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
    } catch (e) {
        // Index ueber das await hinweg nicht wiederverwenden -- die Liste kann
        // zwischenzeitlich neu geladen/umsortiert sein (Rollback traefe sonst
        // ein fremdes Element). Frisch suchen.
        const i = rawAppointments.value.findIndex((a) => a.id === full.id);
        if (i >= 0 && backup) rawAppointments.value[i] = backup; // Rollback
        alert(e.response?.data?.detail || t('appointmentModal.errorSave'));
    } finally {
        movingIds.delete(full.id);
    }
}
</script>

<template>
    <div class="cal">
        <div class="cal-toolbar">
            <div class="cal-nav">
                <button class="icon-btn" :title="$t('calendar.prevTitle')" @click="goPrev"><Icon name="chevron-left" :size="18" /></button>
                <button class="btn-ghost btn-today" @click="goToday">{{ $t('calendar.today') }}</button>
                <button class="icon-btn" :title="$t('calendar.nextTitle')" @click="goNext"><Icon name="chevron-right" :size="18" /></button>
                <h2 class="cal-label">{{ rangeLabel }}</h2>
            </div>
            <div class="cal-actions">
                <div class="view-switch">
                    <button :class="{ active: viewMode === 'month' }" @click="viewMode = 'month'">{{ $t('calendar.monthView') }}</button>
                    <button :class="{ active: viewMode === 'week' }" @click="viewMode = 'week'">{{ $t('calendar.weekView') }}</button>
                </div>
                <button v-if="canManage" class="btn" @click="openNew(null)"><Icon name="plus" :size="17" /> {{ $t('calendar.addButton') }}</button>
            </div>
        </div>

        <div class="cal-body card" :class="{ loading }">
            <CalendarMonthGrid v-if="viewMode === 'month'" :month-date="anchor" :items="items" :can-manage="canManage"
                @select-item="openEdit" @select-day="openNew" @move-item="onMoveItem" />
            <CalendarWeekGrid v-else :week-start="mondayOf(anchor)" :items="items"
                @select-item="openEdit" @select-slot="openNew" />
        </div>

        <AppointmentModal v-if="showModal" :appointment="editing" :default-date="modalDefaultDate"
            :readonly="readonlyMode" :kind="editingKind" :holiday-employee-name="readonlyHolidayName"
            @saved="onSaved" @deleted="onDeleted" @close="showModal = false" />
    </div>
</template>

<style scoped>
.cal { display: flex; flex-direction: column; gap: 16px; height: calc(100vh - 110px); }
.cal-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.cal-nav { display: flex; align-items: center; gap: 8px; }
.icon-btn { background: var(--surface-2); border: 1px solid var(--line); width: 34px; height: 34px; border-radius: 9px; cursor: pointer; color: var(--ink); display: flex; align-items: center; justify-content: center; }
.icon-btn:hover { background: var(--line); }
.btn-today { padding: 8px 14px; font-size: 0.82rem; }
.cal-label { font-size: 1.05rem; margin-left: 8px; text-transform: capitalize; }
.cal-actions { display: flex; align-items: center; gap: 12px; }
.view-switch { display: flex; background: var(--surface-2); border: 1px solid var(--line); border-radius: 9px; padding: 3px; }
.view-switch button { background: none; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; color: var(--ink-soft); font-size: 0.84rem; font-weight: 600; }
.view-switch button.active { background: var(--accent); color: #fff; }
.cal-body { flex: 1; padding: 16px; min-height: 0; transition: opacity 0.15s; }
.cal-body.loading { opacity: 0.6; }

@media (max-width: 720px) {
    .cal { height: auto; }
    .cal-label { display: none; }
}
</style>
