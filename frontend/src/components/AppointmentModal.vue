<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from './Icon.vue';
import CustomerPicker from './CustomerPicker.vue';
import { TEMPLATE_COLORS, COLOR_HEX } from '../fieldTypes';
import { bcp47Locale } from '../i18n';

const props = defineProps({
    appointment: { type: Object, default: null },   // vorhandener Termin (Bearbeiten) oder null (Neu); bei kind=holiday: rohes HolidayRequest-Objekt
    defaultDate: { type: Date, default: null },      // vorbelegter Tag/Zeitpunkt beim Anlegen aus dem Grid
    // Paket 1, Punkt 2 (Lese-Modal): readonly zeigt dasselbe Modal-Grundgeruest
    // (Backdrop/Card/Kopf/Fuss) ohne Edit/Delete -- statt eines Duplikat-
    // Bausteins nur ein zusaetzlicher Anzeige-Zweig je nach "kind". Fuer
    // calendar.view-Nutzer OHNE calendar.manage bei Termin-Klick, UND fuer
    // Urlaubs-Items (kind=holiday), die vorher gar nicht klickbar waren.
    readonly: { type: Boolean, default: false },
    kind: { type: String, default: 'appointment' },  // 'appointment' | 'holiday'
    holidayEmployeeName: { type: String, default: '' },
});
const emit = defineEmits(['saved', 'deleted', 'close']);
const { t } = useI18n();

const isEdit = !!props.appointment?.id;
const isReadonlyHoliday = props.readonly && props.kind === 'holiday';
const isReadonlyAppointment = props.readonly && props.kind === 'appointment';

function pad(n) { return String(n).padStart(2, '0'); }
function toDateStr(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }
function toTimeStr(d) { return `${pad(d.getHours())}:${pad(d.getMinutes())}`; }

// Ausgangswerte: bearbeiten -> aus dem Termin, neu -> defaultDate (oder jetzt), volle Stunde, 1h Dauer
const initStart = props.appointment ? new Date(props.appointment.startsAt) : (props.defaultDate || (() => { const d = new Date(); d.setMinutes(0, 0, 0); return d; })());
const initEnd = props.appointment ? new Date(props.appointment.endsAt) : new Date(initStart.getTime() + 60 * 60 * 1000);
// Bei ganztaegigen Terminen ist endsAt exklusiv (naechster Mitternacht) -- fuer die
// Anzeige im Datumsfeld einen Tag zurueckrechnen (inklusives Enddatum fuer den User).
const initEndDisplay = props.appointment?.allDay ? new Date(initEnd.getTime() - 86400000) : initEnd;

const form = ref({
    title: props.appointment?.title || '',
    customer: props.appointment?.customer || null,
    assignedTo: props.appointment?.assignedTo || '',
    allDay: props.appointment?.allDay || false,
    startDate: toDateStr(initStart),
    startTime: toTimeStr(initStart),
    endDate: toDateStr(initEndDisplay),
    endTime: toTimeStr(initEnd),
    note: props.appointment?.note || '',
    color: props.appointment?.color || 'blue',
    reminderDate: props.appointment?.reminderAt ? toDateStr(new Date(props.appointment.reminderAt)) : '',
    reminderTime: props.appointment?.reminderAt ? toTimeStr(new Date(props.appointment.reminderAt)) : '',
});

const employees = ref([]);
const saving = ref(false);
const deleting = ref(false);
const error = ref('');
const resolvedCustomerName = ref('');

onMounted(async () => {
    if (isReadonlyHoliday) return; // braucht weder Mitarbeiterliste noch Kundennamen
    try {
        const { data } = await api.get('/users/picker');
        employees.value = data['member'] || data['hydra:member'] || [];
    } catch { /* Dropdown bleibt leer, Zuweisung ist optional */ }

    if (isReadonlyAppointment && props.appointment?.customer) {
        try {
            const iri = props.appointment.customer;
            const { data } = await api.get(iri.replace('/api', ''));
            resolvedCustomerName.value = data.city ? `${data.company}, ${data.city}` : data.company;
        } catch { /* Kundenname bleibt leer */ }
    }
});

const assignedToName = computed(() => {
    if (!props.appointment?.assignedTo) return '';
    const id = Number(String(props.appointment.assignedTo).split('/').pop());
    return employees.value.find((u) => u.id === id)?.displayName || '';
});

// Zeitraum-Anzeige im Lese-Modal (Termin): ganztaegig zeigt nur Datum(-spanne),
// sonst Datum+Uhrzeit je Ende.
const readonlyRange = computed(() => {
    if (!props.appointment) return '';
    const s = new Date(props.appointment.startsAt);
    const e = new Date(props.appointment.endsAt);
    if (props.appointment.allDay) {
        const eDisplay = new Date(e.getTime() - 86400000); // exklusiv -> inklusiv fuer die Anzeige
        const fmt = (d) => d.toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
        return fmt(s) === fmt(eDisplay) ? fmt(s) : `${fmt(s)} – ${fmt(eDisplay)}`;
    }
    const fmtDT = (d) => d.toLocaleString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    return `${fmtDT(s)} – ${fmtDT(e)}`;
});

// Zeitraum-Anzeige im Lese-Modal (Urlaub): HolidayRequest::startsAt/endsAt sind
// beide INKLUSIV (siehe HolidayRequestProcessor-Kommentar, Modul #9) -- hier
// wird bewusst das rohe appointment-Prop (== HolidayRequest) direkt formatiert,
// keine Grid-Anpassung wie in CalendarView::toHolidayItem noetig.
const holidayRange = computed(() => {
    if (!props.appointment) return '';
    const fmt = (d) => new Date(d).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
    return `${fmt(props.appointment.startsAt)} – ${fmt(props.appointment.endsAt)}`;
});

function buildIso(dateStr, timeStr) {
    if (!dateStr) return null;
    return `${dateStr}T${timeStr || '00:00'}`;
}

async function save() {
    error.value = '';
    if (!form.value.title.trim()) {
        error.value = t('appointmentModal.errorTitleRequired');
        return;
    }
    if (!form.value.startDate || !form.value.endDate) {
        error.value = t('appointmentModal.errorDatesRequired');
        return;
    }

    let startsAt, endsAt;
    if (form.value.allDay) {
        startsAt = buildIso(form.value.startDate, '00:00');
        // endsAt ist exklusiv -- inklusives Enddatum + 1 Tag
        const endExclusive = new Date(`${form.value.endDate}T00:00`);
        endExclusive.setDate(endExclusive.getDate() + 1);
        endsAt = `${toDateStr(endExclusive)}T00:00`;
    } else {
        startsAt = buildIso(form.value.startDate, form.value.startTime);
        endsAt = buildIso(form.value.endDate, form.value.endTime);
    }
    if (new Date(endsAt) <= new Date(startsAt)) {
        error.value = t('appointmentModal.errorEndBeforeStart');
        return;
    }

    const payload = {
        title: form.value.title.trim(),
        customer: form.value.customer || null,
        assignedTo: form.value.assignedTo || null,
        startsAt,
        endsAt,
        allDay: form.value.allDay,
        note: form.value.note || null,
        color: form.value.color,
        reminderAt: buildIso(form.value.reminderDate, form.value.reminderTime),
    };

    saving.value = true;
    try {
        if (isEdit) {
            await api.patch(`/appointments/${props.appointment.id}`, payload, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        } else {
            await api.post('/appointments', payload, { headers: { 'Content-Type': 'application/ld+json' } });
        }
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.detail || t('appointmentModal.errorSave');
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!confirm(t('appointmentModal.confirmDelete', { title: form.value.title }))) return;
    deleting.value = true;
    try {
        await api.delete(`/appointments/${props.appointment.id}`);
        emit('deleted');
    } catch {
        error.value = t('appointmentModal.errorDelete');
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal card">
            <div class="modal-head">
                <h2 v-if="isReadonlyHoliday">{{ $t('calendar.holidayItemTitle', { name: holidayEmployeeName }) }}</h2>
                <h2 v-else-if="isReadonlyAppointment">{{ appointment.title }}</h2>
                <h2 v-else>{{ isEdit ? $t('appointmentModal.titleEdit') : $t('appointmentModal.titleNew') }}</h2>
                <button class="icon-close" @click="emit('close')"><Icon name="x" :size="16" /></button>
            </div>

            <!-- Lese-Modal: Urlaub (nur Name + Zeitraum, siehe Auftrag) -->
            <div v-if="isReadonlyHoliday" class="modal-body">
                <div class="ro-row">
                    <span class="ro-label">{{ $t('appointmentModal.employeeLabel') }}</span>
                    <span class="ro-value">{{ holidayEmployeeName }}</span>
                </div>
                <div class="ro-row">
                    <span class="ro-label">{{ $t('appointmentModal.readonlyRangeLabel') }}</span>
                    <span class="ro-value">{{ holidayRange }}</span>
                </div>
            </div>

            <!-- Lese-Modal: Termin (Titel/Zeitraum/Kunde/Mitarbeiter/Notiz, keine Edit/Delete-Buttons) -->
            <div v-else-if="isReadonlyAppointment" class="modal-body">
                <div class="ro-row">
                    <span class="ro-label">{{ $t('appointmentModal.readonlyRangeLabel') }}</span>
                    <span class="ro-value">{{ readonlyRange }}</span>
                </div>
                <div class="ro-row">
                    <span class="ro-label">{{ $t('appointmentModal.customerLabel') }}</span>
                    <span class="ro-value">{{ resolvedCustomerName || '—' }}</span>
                </div>
                <div class="ro-row">
                    <span class="ro-label">{{ $t('appointmentModal.employeeLabel') }}</span>
                    <span class="ro-value">{{ assignedToName || '—' }}</span>
                </div>
                <div class="ro-row" v-if="appointment.note">
                    <span class="ro-label">{{ $t('appointmentModal.noteLabel') }}</span>
                    <span class="ro-value">{{ appointment.note }}</span>
                </div>
            </div>

            <!-- Bearbeiten/Anlegen (unveraendert) -->
            <div v-else class="modal-body">
                <label>{{ $t('appointmentModal.titleLabel') }}
                    <input v-model="form.title" class="input" :placeholder="$t('appointmentModal.titlePlaceholder')" autofocus>
                </label>

                <label>{{ $t('appointmentModal.customerLabel') }}
                    <CustomerPicker v-model="form.customer" />
                </label>

                <div class="grid2">
                    <label>{{ $t('appointmentModal.employeeLabel') }}
                        <select v-model="form.assignedTo" class="input">
                            <option value="">{{ $t('appointmentModal.employeeNone') }}</option>
                            <option v-for="u in employees" :key="u.id" :value="`/api/users/${u.id}`">{{ u.displayName }}</option>
                        </select>
                    </label>
                    <label class="color-field">{{ $t('appointmentModal.colorLabel') }}
                        <div class="color-dots">
                            <button v-for="c in TEMPLATE_COLORS" :key="c" type="button" class="color-dot"
                                :class="{ active: form.color === c }" :style="{ background: COLOR_HEX[c] }"
                                :title="c" @click="form.color = c"></button>
                        </div>
                    </label>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" v-model="form.allDay"> {{ $t('appointmentModal.allDayLabel') }}
                </label>

                <div class="grid2">
                    <label>{{ $t('appointmentModal.startLabel') }}
                        <div class="date-time">
                            <input type="date" v-model="form.startDate" class="input">
                            <input v-if="!form.allDay" type="time" v-model="form.startTime" class="input">
                        </div>
                    </label>
                    <label>{{ $t('appointmentModal.endLabel') }}
                        <div class="date-time">
                            <input type="date" v-model="form.endDate" class="input">
                            <input v-if="!form.allDay" type="time" v-model="form.endTime" class="input">
                        </div>
                    </label>
                </div>

                <label>{{ $t('appointmentModal.noteLabel') }}
                    <textarea v-model="form.note" class="input" rows="3" :placeholder="$t('appointmentModal.notePlaceholder')"></textarea>
                </label>

                <label>
                    <span class="reminder-label"><Icon name="bell" :size="14" /> {{ $t('appointmentModal.reminderLabel') }}</span>
                    <div class="date-time">
                        <input type="date" v-model="form.reminderDate" class="input">
                        <input type="time" v-model="form.reminderTime" class="input" :disabled="!form.reminderDate">
                    </div>
                </label>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div class="modal-foot">
                <template v-if="readonly">
                    <div class="foot-spacer"></div>
                    <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.close') }}</button>
                </template>
                <template v-else>
                    <button v-if="isEdit" class="btn btn-ghost danger" :disabled="deleting" @click="remove">
                        {{ deleting ? $t('common.deleting') : $t('common.delete') }}
                    </button>
                    <div class="foot-spacer"></div>
                    <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.cancel') }}</button>
                    <button class="btn" :disabled="saving" @click="save">{{ saving ? $t('common.saving') : $t('common.save') }}</button>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(5,8,14,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.modal { width: 100%; max-width: 560px; max-height: 92vh; display: flex; flex-direction: column; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
.modal-head h2 { font-size: 1.1rem; }
.icon-close { background: var(--surface-2); border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.modal-body { padding: 22px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.date-time { display: flex; gap: 8px; }
.checkbox-row { flex-direction: row; align-items: center; gap: 8px; font-weight: 500; color: var(--ink); }
.checkbox-row input { width: auto; }
.reminder-label { display: flex; align-items: center; gap: 6px; }
.color-field { justify-content: flex-end; }
.color-dots { display: flex; gap: 6px; padding-top: 3px; }
.color-dot { width: 22px; height: 22px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; }
.color-dot.active { border-color: var(--ink); }
textarea.input { resize: vertical; font-family: inherit; }
.form-error { color: var(--red); font-size: 0.86rem; padding: 0 24px; }
.modal-foot { display: flex; align-items: center; gap: 12px; padding: 18px 24px; border-top: 1px solid var(--line); }
.foot-spacer { flex: 1; }
.btn-ghost.danger { color: var(--red); }
.btn-ghost.danger:hover { background: rgba(239,68,68,0.12); }

.ro-row { display: flex; flex-direction: column; gap: 4px; }
.ro-label { font-size: 0.78rem; font-weight: 600; color: var(--ink-soft); }
.ro-value { font-size: 0.94rem; color: var(--ink); }

@media (max-width: 520px) { .grid2 { grid-template-columns: 1fr; } }
</style>
