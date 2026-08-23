<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { bcp47Locale } from '../i18n';
import api from '../api';
import Icon from '../components/Icon.vue';

const auth = useAuthStore();
const router = useRouter();
const { t } = useI18n();

function greeting() {
    const h = new Date().getHours();
    if (h < 11) return t('dashboard.greetingMorning');
    if (h < 18) return t('dashboard.greetingDay');
    return t('dashboard.greetingEvening');
}

const stats = computed(() => [
    { key: 'customers', label: t('dashboard.statCustomers'), value: statValues.value.customers, icon: 'users' },
    { key: 'templates', label: t('dashboard.statTemplates'), value: statValues.value.templates, icon: 'templates' },
    { key: 'submissions', label: t('dashboard.statSubmissions'), value: statValues.value.submissions, icon: 'file' },
    { key: 'appointments', label: t('dashboard.statAppointmentsToday'), value: statValues.value.appointments, icon: 'calendar' },
]);
// Werte getrennt von den (jetzt uebersetzten, also reaktiven) Labels halten --
// stats selbst ist ein computed, kann also nicht mehr direkt per .find(...).value
// befuellt werden wie vorher mit einem ref(Array).
const statValues = ref({ customers: '…', templates: '…', submissions: '…', appointments: '…' });

async function loadCount(resource, key, params = { itemsPerPage: 1 }) {
    try {
        const { data } = await api.get(`/${resource}`, { params });
        const total = data['totalItems'] ?? data['hydra:totalItems'] ?? 0;
        statValues.value[key] = String(total);
    } catch {
        statValues.value[key] = '—';
    }
}

// Modul #8: "Termine heute" -- Fenster von Mitternacht bis Mitternacht ueber
// die Range-API (siehe engine AppointmentRangeProvider, Ueberlappungslogik).
function todayRangeParams() {
    const from = new Date(); from.setHours(0, 0, 0, 0);
    const to = new Date(from); to.setDate(to.getDate() + 1);
    return { from: from.toISOString(), to: to.toISOString(), itemsPerPage: 1 };
}

// Karte "Anstehende Erinnerungen" (Schritt "Erinnerungen"): faellige, aber
// noch nicht verstrichene Erinnerungen -- eigener Dashboard-Query-Endpunkt
// (kein Mailer im Projekt, kein Dismiss in v1, siehe /appointments/reminders_due).
const reminders = ref([]);
const remindersLoading = ref(true);

async function loadReminders() {
    remindersLoading.value = true;
    try {
        const { data } = await api.get('/appointments/reminders_due');
        reminders.value = data['member'] || data['hydra:member'] || [];
    } catch {
        reminders.value = [];
    } finally {
        remindersLoading.value = false;
    }
}

function fmtReminderDate(iso) {
    return new Date(iso).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function goToCalendar() {
    router.push({ name: 'calendar' });
}

// Karte "Offene Urlaubsanträge" (Modul #9): nur fuer holiday.manage sichtbar --
// Badge ist die Gesamtzahl status=pending (nicht nur die eigenen, siehe
// HolidayRequestScopeProvider: ein manage-Nutzer sieht ohnehin alle).
const canManageHoliday = computed(() => auth.can('holiday.manage'));
const pendingHolidayCount = ref(null);

async function loadPendingHoliday() {
    if (!canManageHoliday.value) return;
    try {
        const { data } = await api.get('/holiday_requests', { params: { status: 'pending', itemsPerPage: 1 } });
        pendingHolidayCount.value = data['totalItems'] ?? data['hydra:totalItems'] ?? 0;
    } catch {
        pendingHolidayCount.value = null;
    }
}

function goToHoliday() {
    router.push({ name: 'holiday' });
}

function goToSubmissions() {
    router.push({ name: 'submissions' });
}

// ---- Verbesserungen Paket 2, Punkt 1: Dashboard-Ausbau ----
// Alle drei Karten nutzen NUR bestehende Lese-Endpunkte (kein neuer Provider) --
// bevorzugt laut Auftrag.

// "Diese Woche": naechste Termine von heute bis (exklusiv) naechsten Montag,
// ueber die bestehende Range-Route. Appointment ist ein Gemeinschaftskalender
// OHNE Eigentuemer-Scoping (siehe Verbesserungs-Durchlauf Punkt 1) -- ohne
// calendar.view/manage liefert die Route bewusst 403 statt leer gescopt zu
// werden. Graceful: Karte zeigt sich dann einfach nicht, statt das
// Dashboard mit einem unbehandelten Fehler zu brechen.
const weekAppointments = ref([]);
const weekLoading = ref(true);
const weekAvailable = ref(true);

function weekRangeParams() {
    const from = new Date(); from.setHours(0, 0, 0, 0);
    const dow = (from.getDay() + 6) % 7; // Montag = 0
    const to = new Date(from);
    to.setDate(to.getDate() + (6 - dow) + 1); // naechster Montag 00:00 (exklusiv = Ende Sonntag)
    return { from: from.toISOString(), to: to.toISOString(), itemsPerPage: 5 };
}

async function loadWeekAppointments() {
    weekLoading.value = true;
    try {
        const { data } = await api.get('/appointments', { params: weekRangeParams() });
        weekAppointments.value = data['member'] || data['hydra:member'] || [];
        weekAvailable.value = true;
    } catch (e) {
        weekAvailable.value = e.response?.status !== 403;
        weekAppointments.value = [];
    } finally {
        weekLoading.value = false;
    }
}

function fmtWeekItem(appt) {
    const d = new Date(appt.startsAt);
    if (appt.allDay) {
        return d.toLocaleDateString(bcp47Locale(), { weekday: 'short', day: '2-digit', month: '2-digit' });
    }
    return d.toLocaleString(bcp47Locale(), { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

// "Meine Urlaubsanträge": IMMER clientseitig auf die eigenen gefiltert (Muster
// UrlaubView::myRequests) -- die Collection liefert holiday.view/manage-
// Haltern sonst ALLE Antraege, nicht nur eigene. Anders als "Letzte Scheine"
// unten bewusst KEIN generischer Feed, weil diese Karte explizit "Meine" heisst.
const myHolidayAll = ref([]);
const myHolidayLoading = ref(true);
const HOLIDAY_STATUS_KEY = { pending: 'holiday.statusPending', approved: 'holiday.statusApproved', rejected: 'holiday.statusRejected', withdrawn: 'holiday.statusWithdrawn' };
const HOLIDAY_STATUS_COLOR = { pending: 'amber', approved: 'green', rejected: 'red', withdrawn: 'default' };

async function loadMyHoliday() {
    myHolidayLoading.value = true;
    try {
        const { data } = await api.get('/holiday_requests', { params: { itemsPerPage: 200 } });
        const all = data['member'] || data['hydra:member'] || [];
        const myIri = auth.user ? `/api/users/${auth.user.id}` : null;
        myHolidayAll.value = all.filter((e) => e.requestedBy === myIri);
    } catch {
        myHolidayAll.value = [];
    } finally {
        myHolidayLoading.value = false;
    }
}

const myHolidayRecent = computed(() =>
    [...myHolidayAll.value].sort((a, b) => new Date(b.startsAt) - new Date(a.startsAt)).slice(0, 5)
);
const holidayTakenDays = computed(() => {
    const currentYear = new Date().getFullYear();
    return myHolidayAll.value
        .filter((e) => e.status === 'approved' && new Date(e.startsAt).getFullYear() === currentYear)
        .reduce((sum, e) => sum + (e.requestedDays || 0), 0);
});
const holidayRemainingDays = computed(() => (auth.user?.vacationDaysPerYear ?? 30) - holidayTakenDays.value);

function fmtHolidayDate(iso) {
    return new Date(iso).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// "Letzte Scheine": KEIN Eigene-Filter -- bewusst anders als die Urlaubskarte
// (siehe Auftrag: "fuer Nutzer ohne submissions.view greift das Scoping
// automatisch"). SubmissionScopeProvider liefert ohne submissions.view/manage
// schon nur die eigenen; wer submissions.view/manage hat, sieht hier bewusst
// die zuletzt erstellten ALLER Nutzer -- diese Karte heisst nicht "Meine".
const recentSubmissions = ref([]);
const recentSubmissionsLoading = ref(true);

async function loadRecentSubmissions() {
    recentSubmissionsLoading.value = true;
    try {
        const { data } = await api.get('/form_submissions', { params: { itemsPerPage: 5 } });
        recentSubmissions.value = data['member'] || data['hydra:member'] || [];
    } catch {
        recentSubmissions.value = [];
    } finally {
        recentSubmissionsLoading.value = false;
    }
}

function fmtSubmissionDate(iso) {
    return new Date(iso).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

onMounted(() => {
    loadCount('customers', 'customers');
    loadCount('form_templates', 'templates');
    loadCount('form_submissions', 'submissions');
    loadCount('appointments', 'appointments', todayRangeParams());
    loadReminders();
    loadPendingHoliday();
    loadWeekAppointments();
    loadMyHoliday();
    loadRecentSubmissions();
});
</script>

<template>
    <div class="dash">
        <div class="welcome card">
            <h2>{{ greeting() }}, {{ auth.user?.displayName }}</h2>
            <p>{{ $t('dashboard.welcomeText') }}</p>
        </div>

        <div class="stat-grid">
            <div v-for="s in stats" :key="s.key" class="stat card">
                <span class="stat-icon"><Icon :name="s.icon" :size="22" /></span>
                <div>
                    <b>{{ s.value }}</b>
                    <span>{{ s.label }}</span>
                </div>
            </div>
        </div>

        <div class="card-grid">
            <div class="card reminders" @click="goToCalendar">
                <div class="reminders-head">
                    <span class="stat-icon"><Icon name="bell" :size="20" /></span>
                    <div class="reminders-title">
                        <b>{{ $t('dashboard.remindersTitle') }}</b>
                        <span>{{ $t('dashboard.remindersSubtitle') }}</span>
                    </div>
                    <span v-if="reminders.length" class="reminders-badge">{{ reminders.length }}</span>
                </div>
                <p v-if="remindersLoading" class="reminders-empty">{{ $t('common.loading') }}</p>
                <p v-else-if="!reminders.length" class="reminders-empty">{{ $t('dashboard.remindersEmpty') }}</p>
                <ul v-else class="reminders-list">
                    <li v-for="r in reminders.slice(0, 5)" :key="r.id">
                        <b>{{ r.title }}</b>
                        <span>{{ $t('dashboard.reminderDate', { date: fmtReminderDate(r.startsAt) }) }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="canManageHoliday" class="card reminders" @click="goToHoliday">
                <div class="reminders-head">
                    <span class="stat-icon"><Icon name="umbrella" :size="20" /></span>
                    <div class="reminders-title">
                        <b>{{ $t('dashboard.holidayCardTitle') }}</b>
                        <span>{{ $t('dashboard.holidayCardSubtitle') }}</span>
                    </div>
                    <span v-if="pendingHolidayCount" class="reminders-badge">{{ pendingHolidayCount }}</span>
                </div>
                <p v-if="pendingHolidayCount === 0" class="reminders-empty">{{ $t('dashboard.holidayCardEmpty') }}</p>
            </div>

            <div v-if="weekAvailable" class="card reminders" @click="goToCalendar">
                <div class="reminders-head">
                    <span class="stat-icon"><Icon name="calendar" :size="20" /></span>
                    <div class="reminders-title">
                        <b>{{ $t('dashboard.weekCardTitle') }}</b>
                        <span>{{ $t('dashboard.weekCardSubtitle') }}</span>
                    </div>
                    <span v-if="weekAppointments.length" class="reminders-badge">{{ weekAppointments.length }}</span>
                </div>
                <p v-if="weekLoading" class="reminders-empty">{{ $t('common.loading') }}</p>
                <p v-else-if="!weekAppointments.length" class="reminders-empty">{{ $t('dashboard.weekCardEmpty') }}</p>
                <ul v-else class="reminders-list">
                    <li v-for="a in weekAppointments" :key="a.id">
                        <b>{{ a.title }}</b>
                        <span>{{ fmtWeekItem(a) }}</span>
                    </li>
                </ul>
            </div>

            <div class="card reminders" @click="goToHoliday">
                <div class="reminders-head">
                    <span class="stat-icon"><Icon name="umbrella" :size="20" /></span>
                    <div class="reminders-title">
                        <b>{{ $t('dashboard.myHolidayCardTitle') }}</b>
                        <span>{{ $t('dashboard.myHolidayCardSubtitle', { days: holidayRemainingDays }) }}</span>
                    </div>
                </div>
                <p v-if="myHolidayLoading" class="reminders-empty">{{ $t('common.loading') }}</p>
                <p v-else-if="!myHolidayRecent.length" class="reminders-empty">{{ $t('holiday.myRequestsEmpty') }}</p>
                <ul v-else class="reminders-list">
                    <li v-for="e in myHolidayRecent" :key="e.id">
                        <b>{{ fmtHolidayDate(e.startsAt) }} – {{ fmtHolidayDate(e.endsAt) }}</b>
                        <span class="status-badge" :class="HOLIDAY_STATUS_COLOR[e.status]">{{ $t(HOLIDAY_STATUS_KEY[e.status] || e.status) }}</span>
                    </li>
                </ul>
            </div>

            <div class="card reminders" @click="goToSubmissions">
                <div class="reminders-head">
                    <span class="stat-icon"><Icon name="file" :size="20" /></span>
                    <div class="reminders-title">
                        <b>{{ $t('dashboard.recentSubmissionsTitle') }}</b>
                        <span>{{ $t('dashboard.recentSubmissionsSubtitle') }}</span>
                    </div>
                </div>
                <p v-if="recentSubmissionsLoading" class="reminders-empty">{{ $t('common.loading') }}</p>
                <p v-else-if="!recentSubmissions.length" class="reminders-empty">{{ $t('submissions.empty') }}</p>
                <ul v-else class="reminders-list">
                    <li v-for="s in recentSubmissions" :key="s.id">
                        <b>{{ s.templateName || $t('submissions.defaultFormName') }}</b>
                        <span>{{ s.customer?.company || fmtSubmissionDate(s.createdAt) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dash { display: flex; flex-direction: column; gap: 22px; }
.welcome { padding: 28px 30px; }
.welcome h2 { font-size: 1.25rem; margin-bottom: 6px; }
.welcome p { color: var(--ink-soft); font-size: 0.92rem; max-width: 620px; }
.stat small { display: block; color: var(--ink-soft); font-size: 0.72rem; margin-top: 2px; }

.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
}
.stat {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 22px;
}
.stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: var(--surface-2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}
.stat b { display: block; font-size: 1.5rem; font-weight: 800; }
.stat span { color: var(--ink-soft); font-size: 0.82rem; }

/* Paket 2: mehrere Listen-Karten nebeneinander statt untereinander gestapelt */
.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 18px;
    align-items: start;
}

.reminders { padding: 22px 24px; cursor: pointer; transition: border-color 0.15s; }
.reminders:hover { border-color: var(--accent); }
.reminders-head { display: flex; align-items: center; gap: 16px; }
.reminders-title { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.reminders-title b { font-size: 0.98rem; }
.reminders-title span { color: var(--ink-soft); font-size: 0.8rem; }
.reminders-badge {
    background: var(--amber); color: #1a1204; font-weight: 800; font-size: 0.82rem;
    min-width: 26px; height: 26px; border-radius: 999px; display: flex; align-items: center; justify-content: center; padding: 0 6px;
}
.reminders-empty { color: var(--ink-soft); font-size: 0.86rem; margin-top: 14px; }
.reminders-list { list-style: none; margin-top: 16px; display: flex; flex-direction: column; gap: 10px; }
.reminders-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; background: var(--surface-2); border-radius: 10px; }
.reminders-list li b { font-size: 0.88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.reminders-list li span { color: var(--ink-soft); font-size: 0.78rem; flex-shrink: 0; }

.status-badge { font-size: 0.68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
.status-badge.amber { background: rgba(245,158,11,0.16); color: var(--amber); }
.status-badge.green { background: rgba(34,197,94,0.16); color: var(--green); }
.status-badge.red { background: rgba(239,68,68,0.16); color: var(--red); }
.status-badge.default { background: var(--surface); color: var(--ink-soft); }
</style>
