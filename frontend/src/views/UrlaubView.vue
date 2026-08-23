<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import api from '../api';
import { bcp47Locale } from '../i18n';
import Icon from '../components/Icon.vue';
import HolidayRequestForm from '../components/HolidayRequestForm.vue';

const auth = useAuthStore();
const { t } = useI18n();
const canManage = computed(() => auth.can('holiday.manage'));
const myIri = computed(() => (auth.user ? `/api/users/${auth.user.id}` : null));

const entries = ref([]);
const loading = ref(false);
const showForm = ref(false);

const STATUS_KEY = { pending: 'holiday.statusPending', approved: 'holiday.statusApproved', rejected: 'holiday.statusRejected', withdrawn: 'holiday.statusWithdrawn' };
const STATUS_COLOR = { pending: 'amber', approved: 'green', rejected: 'red', withdrawn: 'default' };

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/holiday_requests', { params: { itemsPerPage: 200 } });
        entries.value = data['member'] || data['hydra:member'] || [];
    } catch {
        entries.value = [];
    } finally {
        loading.value = false;
    }
}
onMounted(load);

// "Meine Antraege": auch fuer manage/admin nur die EIGENEN -- die Collection
// liefert ihnen serverseitig alle, hier wird bewusst clientseitig gefiltert.
const myRequests = computed(() =>
    [...entries.value].filter((e) => e.requestedBy === myIri.value)
        .sort((a, b) => new Date(b.startsAt) - new Date(a.startsAt))
);

// "Zu genehmigen": nur fremde offene Antraege -- Selbst-Genehmigung blockt
// der Server ohnehin (siehe HolidayRequestProcessor), hier gar nicht erst anbieten.
const pendingApprovals = computed(() =>
    entries.value.filter((e) => e.status === 'pending' && e.requestedBy !== myIri.value)
        .sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt))
);

// Urlaubskonto: genommen = Summe requestedDays approved, deren Start im
// laufenden Kalenderjahr liegt (clientseitig summiert, siehe Auftrag).
const currentYear = new Date().getFullYear();
const takenDays = computed(() =>
    myRequests.value
        .filter((e) => e.status === 'approved' && new Date(e.startsAt).getFullYear() === currentYear)
        .reduce((sum, e) => sum + (e.requestedDays || 0), 0)
);
const totalDays = computed(() => auth.user?.vacationDaysPerYear ?? 30);
const remainingDays = computed(() => totalDays.value - takenDays.value);

function fmtDate(iso) {
    return new Date(iso).toLocaleDateString(bcp47Locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function onCreated() {
    showForm.value = false;
    await load();
}

// Paket 1, Punkt 1: PDF braucht den Authorization-Header (Token), deshalb per
// axios als Blob statt eines einfachen <a href>-Links -- Muster SubmissionsView.
function openPdf(entry) {
    api.get(`/holiday_requests/${entry.id}/pdf`, { responseType: 'blob' }).then((res) => {
        const url = URL.createObjectURL(res.data);
        window.open(url, '_blank');
    });
}

async function withdraw(entry) {
    if (!confirm(t('holiday.confirmWithdraw', { date: fmtDate(entry.startsAt) }))) return;
    try {
        await api.patch(`/holiday_requests/${entry.id}`, { status: 'withdrawn' }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        await load();
    } catch (e) {
        alert(e.response?.data?.detail || t('holiday.errorWithdraw'));
    }
}

async function approve(entry) {
    try {
        await api.patch(`/holiday_requests/${entry.id}`, { status: 'approved' }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        await load();
    } catch (e) {
        alert(e.response?.data?.detail || t('holiday.errorApprove'));
    }
}

// Ablehnen braucht einen Pflichtgrund -- statt eines eigenen Modals reicht ein
// pro Zeile ein-/ausklappbares Notizfeld (kein neues Modal fuer ein einzelnes
// Textfeld noetig).
const rejecting = ref(null);   // id des Antrags mit offenem Ablehn-Feld
const rejectReason = ref('');

function startReject(entry) {
    rejecting.value = entry.id;
    rejectReason.value = '';
}
async function confirmReject(entry) {
    if (!rejectReason.value.trim()) {
        alert(t('holiday.errorRejectReasonRequired'));
        return;
    }
    try {
        await api.patch(`/holiday_requests/${entry.id}`, {
            status: 'rejected', rejectedReason: rejectReason.value.trim(),
        }, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        rejecting.value = null;
        await load();
    } catch (e) {
        alert(e.response?.data?.detail || t('holiday.errorReject'));
    }
}
</script>

<template>
    <div class="urlaub">
        <div class="account card">
            <div class="account-item">
                <b>{{ totalDays }}</b><span>{{ $t('holiday.accountDaysPerYear') }}</span>
            </div>
            <div class="account-item">
                <b>{{ takenDays }}</b><span>{{ $t('holiday.accountTaken', { year: currentYear }) }}</span>
            </div>
            <div class="account-item highlight">
                <b>{{ remainingDays }}</b><span>{{ $t('holiday.accountRemaining') }}</span>
            </div>
            <button class="btn" @click="showForm = true"><Icon name="plus" :size="17" /> {{ $t('holiday.newRequestButton') }}</button>
        </div>

        <div v-if="canManage" class="card section">
            <h2>{{ $t('holiday.toApproveTitle') }}</h2>
            <p v-if="!pendingApprovals.length" class="empty">{{ $t('holiday.toApproveEmpty') }}</p>
            <div v-else class="approval-list">
                <div v-for="e in pendingApprovals" :key="e.id" class="approval-row">
                    <div class="approval-info">
                        <b>{{ fmtDate(e.startsAt) }} – {{ fmtDate(e.endsAt) }}</b>
                        <span>{{ e.requestedDays }} {{ $t('holiday.workdays') }}<template v-if="e.representative"> · {{ $t('holiday.representative', { name: e.representative }) }}</template></span>
                        <span v-if="e.reason" class="dim">{{ e.reason }}</span>
                    </div>
                    <div v-if="rejecting === e.id" class="reject-box">
                        <input v-model="rejectReason" class="input" :placeholder="$t('holiday.rejectPlaceholder')" autofocus>
                        <button class="btn-xs" @click="confirmReject(e)">{{ $t('common.confirm') }}</button>
                        <button class="btn-xs ghost" @click="rejecting = null">{{ $t('common.cancel') }}</button>
                    </div>
                    <div v-else class="approval-actions">
                        <button class="icon-act" :title="$t('holiday.pdfTitle')" @click="openPdf(e)"><Icon name="file" :size="15" /></button>
                        <button class="btn-xs approve" @click="approve(e)"><Icon name="check" :size="14" /> {{ $t('holiday.approveButton') }}</button>
                        <button class="btn-xs reject" @click="startReject(e)"><Icon name="x" :size="14" /> {{ $t('holiday.rejectButton') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card section">
            <h2>{{ $t('holiday.myRequestsTitle') }}</h2>
            <div v-if="loading" class="empty">{{ $t('common.loading') }}</div>
            <p v-else-if="!myRequests.length" class="empty">{{ $t('holiday.myRequestsEmpty') }}</p>
            <table v-else>
                <thead>
                    <tr>
                        <th>{{ $t('holiday.colPeriod') }}</th>
                        <th>{{ $t('holiday.colWorkdays') }}</th>
                        <th>{{ $t('holiday.colRepresentative') }}</th>
                        <th>{{ $t('holiday.colStatus') }}</th>
                        <th class="right">{{ $t('holiday.colActions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in myRequests" :key="e.id">
                        <td>{{ fmtDate(e.startsAt) }} – {{ fmtDate(e.endsAt) }}</td>
                        <td>{{ e.requestedDays }}</td>
                        <td>{{ e.representative || '—' }}</td>
                        <td>
                            <span class="status-badge" :class="STATUS_COLOR[e.status]">{{ STATUS_KEY[e.status] ? $t(STATUS_KEY[e.status]) : e.status }}</span>
                            <div v-if="e.status === 'rejected' && e.rejectedReason" class="dim reason-note">{{ e.rejectedReason }}</div>
                        </td>
                        <td class="right">
                            <button class="icon-act" :title="$t('holiday.pdfTitle')" @click="openPdf(e)"><Icon name="file" :size="15" /></button>
                            <button v-if="e.status === 'pending' || (e.status === 'approved' && canManage)"
                                class="icon-act danger" :title="$t('holiday.withdrawTitle')" @click="withdraw(e)">
                                <Icon name="trash" :size="15" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <HolidayRequestForm v-if="showForm" @saved="onCreated" @close="showForm = false" />
    </div>
</template>

<style scoped>
.urlaub { display: flex; flex-direction: column; gap: 18px; }
.account { display: flex; align-items: center; gap: 28px; padding: 22px 26px; }
.account-item { display: flex; flex-direction: column; gap: 2px; }
.account-item b { font-size: 1.5rem; font-weight: 800; }
.account-item span { color: var(--ink-soft); font-size: 0.78rem; }
.account-item.highlight b { color: var(--accent); }
.account .btn { margin-left: auto; }

.section { padding: 22px 24px; }
.section h2 { font-size: 1rem; margin-bottom: 14px; }
.empty { color: var(--ink-soft); font-size: 0.86rem; padding: 8px 0; }
.dim { color: var(--ink-soft); font-size: 0.78rem; }

.approval-list { display: flex; flex-direction: column; gap: 10px; }
.approval-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    background: var(--surface-2); border-radius: 10px; padding: 12px 16px;
}
.approval-info { display: flex; flex-direction: column; gap: 2px; }
.approval-info b { font-size: 0.9rem; }
.approval-info span { font-size: 0.78rem; color: var(--ink-soft); }
.approval-actions { display: flex; gap: 8px; }
.btn-xs {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 12px; border-radius: 8px; border: none; cursor: pointer;
    font-size: 0.8rem; font-weight: 600;
}
.btn-xs.approve { background: rgba(34,197,94,0.15); color: var(--green); }
.btn-xs.approve:hover { background: rgba(34,197,94,0.25); }
.btn-xs.reject { background: rgba(239,68,68,0.12); color: var(--red); }
.btn-xs.reject:hover { background: rgba(239,68,68,0.22); }
.btn-xs.ghost { background: var(--line); color: var(--ink); }
.reject-box { display: flex; gap: 8px; align-items: center; flex: 1; min-width: 240px; }
.reject-box .input { flex: 1; padding: 8px 12px; }

table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-soft); padding: 8px 10px; border-bottom: 1px solid var(--line); }
th.right, td.right { text-align: right; }
td { padding: 10px; border-bottom: 1px solid var(--line); font-size: 0.86rem; }
tr:last-child td { border-bottom: none; }
.reason-note { margin-top: 2px; max-width: 220px; }

.status-badge { font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.status-badge.amber { background: rgba(245,158,11,0.14); color: var(--amber); }
.status-badge.green { background: rgba(34,197,94,0.14); color: var(--green); }
.status-badge.red { background: rgba(239,68,68,0.14); color: var(--red); }
.status-badge.default { background: var(--surface-2); color: var(--ink-soft); }

.icon-act { background: none; border: none; cursor: pointer; padding: 6px; border-radius: 7px; color: var(--ink-soft); }
.icon-act:hover { background: var(--surface-2); color: var(--ink); }
.icon-act.danger:hover { color: var(--red); }

@media (max-width: 640px) {
    .account { flex-wrap: wrap; }
    .account .btn { margin-left: 0; width: 100%; justify-content: center; }
}
</style>
